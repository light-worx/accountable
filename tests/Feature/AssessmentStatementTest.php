<?php

namespace Tests\Feature;

use App\Enums\PayeeType;
use App\Enums\TransactionType;
use App\Models\Budget;
use App\Models\Entity;
use App\Models\MoneyAccount;
use App\Models\Payee;
use App\Models\User;
use App\Services\Budgets\BudgetService;
use App\Services\Budgets\SocietyPayees;
use App\Services\Ledger\PostingException;
use App\Services\Ledger\PostingService;
use App\Services\Reports\AssessmentStatements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AssessmentStatementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['church_api.url' => 'https://church.net.za/api/v1', 'church_api.token' => 'test-token']);
    }

    private function circuit(): array
    {
        $circuit = Entity::create(['type' => 'circuit', 'name' => 'Test Circuit', 'external_id' => '10']);
        $user = User::factory()->create();
        $circuit->users()->attach($user->id, ['role' => 'treasurer']);
        $account = MoneyAccount::create(['entity_id' => $circuit->id, 'name' => 'Bank', 'type' => 'bank']);

        return [$circuit, $user, $account, $circuit->generalFund()];
    }

    private function societies(): array
    {
        return [
            ['id' => '100', 'slug' => 'society-x', 'name' => 'Society X', 'email' => 'x@example.org'],
            ['id' => '101', 'slug' => 'society-y', 'name' => 'Society Y', 'email' => null],
        ];
    }

    /** An approved budget for the year with Society X and Society Y at the given annual amounts. */
    private function schedule(Entity $circuit, User $user, int $year, int $x, int $y): Budget
    {
        $service = app(BudgetService::class);
        $budget = $service->create($circuit, $user, $year, 'blank');
        $service->addSocieties($budget, $user, $this->societies());
        $service->setAssessment($budget, $user, '100', Budget::spread($x));
        $service->setAssessment($budget, $user, '101', Budget::spread($y));

        return $service->approve($budget, $user, 'Circuit Quarterly Meeting');
    }

    private function receive(Entity $circuit, User $user, $account, $fund, string $date, int $cents, ?string $societyId): void
    {
        $payee = $societyId ? Payee::where(['entity_id' => $circuit->id, 'society_external_id' => $societyId])->firstOrFail() : null;

        app(PostingService::class)->post($circuit, $user, TransactionType::Income, $date, [[
            'money_account_id' => $account->id,
            'fund_id' => $fund->id,
            'category_id' => app(BudgetService::class)->assessmentCategory($circuit)->id,
            'amount_cents' => $cents,
        ]], null, $payee?->id);
    }

    public function test_societies_become_people_without_duplicates_or_clashing_names(): void
    {
        [$circuit, $user] = $this->circuit();
        Payee::create(['entity_id' => $circuit->id, 'name' => 'Society X', 'type' => 'supplier']);

        $service = app(BudgetService::class);
        $budget = $service->create($circuit, $user, 2026, 'blank');
        $service->addSocieties($budget, $user, $this->societies());
        $service->addSocieties($budget, $user, $this->societies()); // again: harmless

        $this->assertSame(2, Payee::where(['entity_id' => $circuit->id, 'type' => PayeeType::Society->value])->count());
        $this->assertSame('Society X (society 100)', Payee::where('society_external_id', '100')->value('name'));
        $this->assertSame('Society Y', Payee::where('society_external_id', '101')->value('name'));
    }

    public function test_societies_can_be_loaded_straight_from_the_directory(): void
    {
        [$circuit] = $this->circuit();

        Http::fake([
            'church.net.za/api/v1/circuits*' => Http::response(['data' => [[
                'id' => 10, 'slug' => '10', 'name' => 'Test Circuit', 'district' => ['id' => 5, 'name' => 'Central'],
            ]]]),
            'church.net.za/api/v1/societies*' => Http::response(['data' => [
                ['id' => 100, 'slug' => 'society-x', 'name' => 'Society X', 'circuit' => ['id' => 10, 'slug' => '10']],
            ]]),
        ]);

        $this->assertSame(1, app(SocietyPayees::class)->loadFromDirectory($circuit));
        $this->assertSame(0, app(SocietyPayees::class)->loadFromDirectory($circuit));
        $this->assertSame('100', Payee::where('entity_id', $circuit->id)->value('society_external_id'));
    }

    public function test_the_statement_compares_what_is_due_with_what_was_paid(): void
    {
        [$circuit, $user, $account, $fund] = $this->circuit();
        $this->schedule($circuit, $user, 2026, 120_000, 60_000); // R1,000 and R500 a month

        $this->receive($circuit, $user, $account, $fund, '2026-01-10', 10_000, '100');
        $this->receive($circuit, $user, $account, $fund, '2026-02-12', 10_000, '100');
        $this->receive($circuit, $user, $account, $fund, '2026-01-15', 5_000, '101');   // Society Y misses February
        $this->receive($circuit, $user, $account, $fund, '2026-02-20', 2_000, null);    // not attributed to anyone

        $d = app(AssessmentStatements::class)->build($circuit, 2026, 2);
        $x = collect($d['societies'])->firstWhere('external_id', '100');
        $y = collect($d['societies'])->firstWhere('external_id', '101');

        $this->assertSame(20_000, $x['ytd_due']);
        $this->assertSame(20_000, $x['ytd_received']);
        $this->assertSame(0, $x['balance']);
        $this->assertSame(['x@example.org'], $x['emails']);

        $this->assertSame(5_000, $y['month_due']);
        $this->assertSame(0, $y['month_received']);
        $this->assertSame(10_000, $y['ytd_due']);
        $this->assertSame(5_000, $y['ytd_received']);
        $this->assertSame(-5_000, $y['balance']);
        $this->assertSame([0, -5_000], array_column($y['grid'], 'balance'));
        $this->assertSame([], $y['emails']);

        $this->assertCount(1, $x['receipts']);
        $this->assertSame(10_000, $x['receipts'][0]['amount']);

        $this->assertSame(2_000, $d['unattributed']);
        $this->assertStringContainsString('not attributed', $d['issues'][0]['text']);
        $this->assertSame(-5_000, $d['totals']['balance']);
        $this->assertSame(30_000, $d['totals']['ytd_due']);
    }

    public function test_arrears_from_last_year_are_brought_forward(): void
    {
        [$circuit, $user, $account, $fund] = $this->circuit();

        $this->schedule($circuit, $user, 2025, 12_000, 0);
        $this->receive($circuit, $user, $account, $fund, '2025-06-01', 10_000, '100'); // R20 short for the year

        $this->schedule($circuit, $user, 2026, 120_000, 0);
        $this->receive($circuit, $user, $account, $fund, '2026-01-10', 10_000, '100');

        $x = collect(app(AssessmentStatements::class)->build($circuit, 2026, 1)['societies'])->firstWhere('external_id', '100');

        $this->assertSame(-2_000, $x['brought_forward']);
        $this->assertSame(-2_000, $x['balance']); // -2000 + 10000 received - 10000 due
    }

    public function test_no_approved_budget_means_no_figures(): void
    {
        [$circuit, $user] = $this->circuit();
        app(BudgetService::class)->create($circuit, $user, 2026, 'blank'); // a draft is not enough

        $this->assertNull(app(AssessmentStatements::class)->build($circuit, 2026, 3));
    }

    public function test_a_person_from_another_society_or_circuit_is_refused(): void
    {
        [$circuit, $user, $account, $fund] = $this->circuit();
        $other = Entity::create(['type' => 'circuit', 'name' => 'Other Circuit']);
        $foreign = Payee::create(['entity_id' => $other->id, 'name' => 'Not ours']);

        $this->expectException(PostingException::class);
        app(PostingService::class)->post($circuit, $user, TransactionType::Income, '2026-01-10', [[
            'money_account_id' => $account->id, 'fund_id' => $fund->id,
            'category_id' => $circuit->categories()->where('name', 'Interest earned')->value('id'), 'amount_cents' => 1_000,
        ]], null, $foreign->id);
    }

    public function test_the_pdfs_are_served_to_circuit_members_only(): void
    {
        [$circuit, $user] = $this->circuit();
        $this->schedule($circuit, $user, 2026, 120_000, 60_000);

        $summary = route('reports.assessments', ['entity' => $circuit->id, 'period' => '2026-03']);
        $statement = route('reports.assessments.society', ['entity' => $circuit->id, 'period' => '2026-03', 'society' => '100']);

        $this->actingAs($user)->get($summary)->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($user)->get($statement)->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->actingAs($user)->get(route('reports.assessments.society', ['entity' => $circuit->id, 'period' => '2026-03', 'society' => '999']))->assertNotFound();
        $this->actingAs($user)->get(route('reports.assessments', ['entity' => $circuit->id, 'period' => '2025-03']))->assertNotFound(); // no 2025 budget

        $this->actingAs(User::factory()->create())->get($summary)->assertForbidden();

        $society = Entity::create(['type' => 'society', 'name' => 'A Society']);
        $society->users()->attach($user->id, ['role' => 'treasurer']);
        $this->actingAs($user)->get(route('reports.assessments', ['entity' => $society->id, 'period' => '2026-03']))->assertNotFound();
    }
}