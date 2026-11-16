<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\BudgetAssessment;
use App\Models\Entity;
use App\Models\User;
use App\Services\Budgets\BudgetException;
use App\Services\Budgets\BudgetService;
use App\Services\Reports\PeriodStatement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class AssessmentBudgetTest extends TestCase
{
    use RefreshDatabase;

    private function circuit(string $role = 'treasurer'): array
    {
        $entity = Entity::create(['type' => 'circuit', 'name' => 'Test Circuit', 'external_id' => '10']);
        $user = User::factory()->create();
        $entity->users()->attach($user->id, ['role' => $role]);

        return [$entity, $user];
    }

    private function societies(): array
    {
        return [
            ['id' => '100', 'slug' => 'society-x', 'name' => 'Society X', 'email' => 'x@example.org'],
            ['id' => '101', 'slug' => 'society-y', 'name' => 'Society Y', 'email' => null],
        ];
    }

    private function lineFor(Budget $budget, Entity $entity)
    {
        return $budget->lines()->where('category_id', app(BudgetService::class)->assessmentCategory($entity)->id)->first();
    }

    /** A draft circuit budget with two societies, R1,200 and R600 for the year. */
    private function scheduled(): array
    {
        [$entity, $user] = $this->circuit();
        $service = app(BudgetService::class);

        $budget = $service->create($entity, $user, 2026, 'blank');
        $service->addSocieties($budget, $user, $this->societies());
        $service->setAssessment($budget, $user, '100', Budget::spread(120_000));
        $service->setAssessment($budget, $user, '101', Budget::spread(60_000));

        return [$entity, $user, $budget, $service];
    }

    public function test_loading_societies_is_idempotent_and_the_total_feeds_the_3_1_line(): void
    {
        [$entity, $user] = $this->circuit();
        $service = app(BudgetService::class);
        $budget = $service->create($entity, $user, 2026, 'blank');

        $this->assertSame(2, $service->addSocieties($budget, $user, $this->societies()));
        $this->assertSame(0, $service->addSocieties($budget, $user, $this->societies()));
        $this->assertSame(2, $budget->assessments()->count());
        $this->assertSame('x@example.org', $budget->assessments()->where('society_external_id', '100')->value('society_email'));

        $service->setAssessment($budget, $user, '100', Budget::spread(120_000));
        $service->setAssessment($budget, $user, '101', Budget::spread(60_000));

        $line = $this->lineFor($budget, $entity);
        $this->assertSame(180_000, $line->annualCents());
        $this->assertSame(15_000, $line->m1); // 10 000 + 5 000
    }

    public function test_one_month_can_be_adjusted_for_one_society(): void
    {
        [$entity, $user, $budget, $service] = $this->scheduled();

        $months = Budget::spread(120_000);
        $months[2] = 20_000; // March is higher for Society X
        $service->setAssessment($budget, $user, '100', $months);

        $this->assertFalse($budget->assessments()->where('society_external_id', '100')->first()->isEven());
        $this->assertTrue($budget->assessments()->where('society_external_id', '101')->first()->isEven());

        $line = $this->lineFor($budget, $entity);
        $this->assertSame(190_000, $line->annualCents());
        $this->assertSame(25_000, $line->m3);
        $this->assertSame(15_000, $line->m4);
    }

    public function test_removing_a_society_updates_the_line(): void
    {
        [$entity, $user, $budget, $service] = $this->scheduled();

        $service->removeAssessment($budget, $user, '101');

        $this->assertSame(1, $budget->assessments()->count());
        $this->assertSame(120_000, $this->lineFor($budget, $entity)->annualCents());
    }

    public function test_an_approved_schedule_is_frozen_and_a_new_version_copies_it(): void
    {
        [$entity, $user, $budget, $service] = $this->scheduled();
        $service->approve($budget, $user, 'Circuit Quarterly Meeting');

        try {
            $service->setAssessment($budget->fresh(), $user, '100', Budget::spread(1));
            $this->fail('An approved schedule must not be editable.');
        } catch (BudgetException) {
            $this->assertTrue(true);
        }

        try {
            BudgetAssessment::where('budget_id', $budget->id)->first()->update(['m1' => 1]);
            $this->fail('The model must refuse the change as well.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        $v2 = $service->newVersion($budget->fresh(), $user, 'A society joined mid-year');

        $this->assertSame(2, $v2->assessments()->count());
        $this->assertSame(180_000, $this->lineFor($v2, $entity)->annualCents());

        $service->setAssessment($v2, $user, '100', Budget::spread(240_000)); // the draft can change
        $this->assertSame(120_000, $budget->assessments()->where('society_external_id', '100')->first()->annualCents()); // v1 untouched
    }

    public function test_next_years_budget_can_start_from_a_copy_of_the_schedule(): void
    {
        [$entity, $user, $budget, $service] = $this->scheduled();
        $service->approve($budget, $user, 'Circuit Quarterly Meeting');

        $next = $service->create($entity, $user, 2027, 'copy');

        $this->assertSame(2, $next->assessments()->count());
        $this->assertSame(180_000, $this->lineFor($next, $entity)->annualCents());
    }

    public function test_the_management_accounts_use_the_society_total(): void
    {
        [$entity, $user, $budget, $service] = $this->scheduled();
        $service->approve($budget, $user, 'Circuit Quarterly Meeting');

        $s = app(PeriodStatement::class)->build($entity, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));
        $line = collect($s['income']['lines'])->firstWhere('name', 'Assessments received from societies');

        $this->assertSame(15_000, $line['budget']);          // October
        $this->assertSame(150_000, $line['budget_ytd']);     // January to October
        $this->assertSame(180_000, $line['budget_annual']);
    }

    public function test_only_the_treasurer_can_change_the_schedule_and_societies_have_none(): void
    {
        [$entity, $treasurer] = $this->circuit();
        $capturer = User::factory()->create();
        $entity->users()->attach($capturer->id, ['role' => 'capturer']);

        $service = app(BudgetService::class);
        $budget = $service->create($entity, $treasurer, 2026, 'blank');

        try {
            $service->addSocieties($budget, $capturer, $this->societies());
            $this->fail('A bookkeeper must not be able to change the schedule.');
        } catch (BudgetException) {
            $this->assertTrue(true);
        }

        $society = Entity::create(['type' => 'society', 'name' => 'A Society']);
        $society->users()->attach($treasurer->id, ['role' => 'treasurer']);
        $societyBudget = $service->create($society, $treasurer, 2026, 'blank');

        $this->expectException(BudgetException::class); // societies do not have assessment schedules
        $service->addSocieties($societyBudget, $treasurer, $this->societies());
    }
}