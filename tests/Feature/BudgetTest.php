<?php

namespace Tests\Feature;

use App\Enums\TransactionType;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\Entity;
use App\Models\MoneyAccount;
use App\Models\User;
use App\Services\Budgets\BudgetException;
use App\Services\Budgets\BudgetService;
use App\Services\Ledger\PostingService;
use App\Services\Reports\PeriodStatement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class BudgetTest extends TestCase
{
    use RefreshDatabase;

    private function society(string $role = 'treasurer'): array
    {
        $entity = Entity::create(['type' => 'society', 'name' => 'Test Society']);
        $user = User::factory()->create();
        $entity->users()->attach($user->id, ['role' => $role]);

        MoneyAccount::create(['entity_id' => $entity->id, 'name' => 'Bank', 'type' => 'bank', 'opening_balance_cents' => 100_000]);
        $entity->generalFund()->update(['opening_balance_cents' => 100_000]);

        return [$entity, $user];
    }

    private function setLine(Budget $budget, Entity $entity, string $categoryName, int $annualCents): void
    {
        $category = $entity->categories()->where('name', $categoryName)->firstOrFail();
        $line = BudgetLine::where(['budget_id' => $budget->id, 'category_id' => $category->id])->firstOrFail();

        $line->update(array_combine(array_map(fn ($m) => "m{$m}", range(1, 12)), Budget::spread($annualCents)));
    }

    public function test_spreading_an_annual_amount_adds_up_exactly(): void
    {
        $months = Budget::spread(100_000);

        $this->assertCount(12, $months);
        $this->assertSame(100_000, array_sum($months));
        $this->assertLessThanOrEqual(1, max($months) - min($months));
        $this->assertSame(8_334, $months[0]); // 100000 / 12 = 8333 remainder 4: the first four months get the extra cent
        $this->assertSame(8_333, $months[11]);
        $this->assertSame(array_fill(0, 12, 0), Budget::spread(0));
    }

    public function test_a_new_budget_has_a_line_for_every_active_type(): void
    {
        [$entity, $user] = $this->society();

        $budget = app(BudgetService::class)->create($entity, $user, 2026, 'blank');

        $this->assertSame('draft', $budget->status);
        $this->assertSame(1, $budget->version);
        $this->assertSame($entity->categories()->where('is_active', true)->count(), $budget->lines()->count());
    }

    public function test_starting_from_last_years_actuals_spreads_them_evenly(): void
    {
        [$entity, $user] = $this->society();
        $category = $entity->categories()->where('name', 'Sunday offerings (cash collections)')->first();
        $account = $entity->moneyAccounts()->first();

        app(PostingService::class)->post($entity, $user, TransactionType::Income, '2025-06-01', [[
            'money_account_id' => $account->id, 'fund_id' => $entity->generalFund()->id,
            'category_id' => $category->id, 'amount_cents' => 120_000,
        ]]);

        $budget = app(BudgetService::class)->create($entity, $user, 2026, 'actuals');
        $line = $budget->lines()->where('category_id', $category->id)->first();

        $this->assertSame(120_000, $line->annualCents());
        $this->assertTrue($line->isEven());
        $this->assertSame(10_000, $line->m1);
    }

    public function test_an_approved_budget_is_frozen_and_changes_need_a_new_version(): void
    {
        [$entity, $user] = $this->society();
        $service = app(BudgetService::class);

        $v1 = $service->create($entity, $user, 2026, 'blank');
        $this->setLine($v1, $entity, 'Sunday offerings (cash collections)', 120_000); // fine while a draft

        $service->approve($v1, $user, 'Circuit Quarterly Meeting, 18 Feb 2026');
        $this->assertSame('approved', $v1->fresh()->status);

        $line = $v1->lines()->first();
        try {
            $line->update(['m1' => 999]);
            $this->fail('An approved budget line must not be editable.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        try {
            $service->create($entity, $user, 2026, 'blank');
            $this->fail('A second budget for an approved year must not be started this way.');
        } catch (BudgetException) {
            $this->assertTrue(true);
        }

        $v2 = $service->newVersion($v1->fresh(), $user, 'Assessment increased at the quarterly meeting');
        $this->assertSame(2, $v2->version);
        $this->assertSame('draft', $v2->status);
        $this->assertSame(
            120_000,
            $v2->lines()->whereHas('category', fn ($q) => $q->where('name', 'Sunday offerings (cash collections)'))->first()->annualCents(),
        );

        $this->assertSame($v1->id, $service->current($entity, 2026)->id); // v1 stays in force until v2 is approved

        $service->approve($v2, $user, 'Special meeting, 5 May 2026');
        $this->assertSame('superseded', $v1->fresh()->status);
        $this->assertSame($v2->id, $service->current($entity, 2026)->id);
    }

    public function test_only_the_treasurer_can_change_budgets(): void
    {
        [$entity, $treasurer] = $this->society();
        $capturer = User::factory()->create();
        $entity->users()->attach($capturer->id, ['role' => 'capturer']);

        $this->expectException(BudgetException::class);
        app(BudgetService::class)->create($entity, $capturer, 2026, 'blank');
    }

    public function test_a_draft_can_be_discarded_but_an_approved_budget_cannot(): void
    {
        [$entity, $user] = $this->society();
        $service = app(BudgetService::class);

        $draft = $service->create($entity, $user, 2026, 'blank');
        $service->discard($draft, $user);
        $this->assertNull(Budget::find($draft->id));
        $this->assertSame(0, BudgetLine::where('budget_id', $draft->id)->count());

        $approved = $service->create($entity, $user, 2026, 'blank');
        $service->approve($approved, $user, 'Quarterly meeting');

        $this->expectException(BudgetException::class);
        $service->discard($approved->fresh(), $user);
    }

    public function test_statement_shows_the_approved_budget_but_never_a_draft(): void
    {
        [$entity, $user] = $this->society();
        $service = app(BudgetService::class);
        $statement = app(PeriodStatement::class);
        $october = [CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31')];

        $budget = $service->create($entity, $user, 2026, 'blank');
        $this->setLine($budget, $entity, 'Sunday offerings (cash collections)', 120_000);

        $this->assertNull($statement->build($entity, ...$october)['budget']); // still a draft

        $service->approve($budget, $user, 'Quarterly meeting');

        $category = $entity->categories()->where('name', 'Sunday offerings (cash collections)')->first();
        app(PostingService::class)->post($entity, $user, TransactionType::Income, '2026-10-03', [[
            'money_account_id' => $entity->moneyAccounts()->first()->id, 'fund_id' => $entity->generalFund()->id,
            'category_id' => $category->id, 'amount_cents' => 40_000,
        ]]);

        $s = $statement->build($entity, ...$october);
        $line = collect($s['income']['lines'])->firstWhere('name', 'Sunday offerings (cash collections)');

        $this->assertSame(1, $s['budget']['version']);
        $this->assertSame(40_000, $line['period']);
        $this->assertSame(10_000, $line['budget']);          // October only
        $this->assertSame(100_000, $line['budget_ytd']);     // January to October
        $this->assertSame(120_000, $line['budget_annual']);
        $this->assertSame(10_000, $s['income']['budget']);
        $this->assertSame(10_000, $s['surplus']['budget']);

        // A whole-year report compares the full year with the full-year budget.
        $year = $statement->build($entity, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'));
        $this->assertSame(120_000, collect($year['income']['lines'])->firstWhere('name', 'Sunday offerings (cash collections)')['budget']);
    }

    public function test_the_management_accounts_pdf_is_produced_with_a_budget(): void
    {
        [$entity, $user] = $this->society();
        $service = app(BudgetService::class);

        $budget = $service->create($entity, $user, 2026, 'blank');
        $this->setLine($budget, $entity, 'Sunday offerings (cash collections)', 120_000);
        $service->approve($budget, $user, 'Quarterly meeting');

        $this->actingAs($user)
            ->get(route('reports.statement', ['entity' => $entity->id, 'period' => '2026-10']))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
}