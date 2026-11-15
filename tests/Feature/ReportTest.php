<?php

namespace Tests\Feature;

use App\Enums\TransactionType;
use App\Models\Entity;
use App\Models\MoneyAccount;
use App\Models\User;
use App\Services\Ledger\PostingService;
use App\Services\Reports\PeriodStatement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    private function society(): array
    {
        $entity = Entity::create(['type' => 'society', 'name' => 'Test Society']);
        $user = User::factory()->create();
        $entity->users()->attach($user->id, ['role' => 'treasurer']);

        $account = MoneyAccount::create(['entity_id' => $entity->id, 'name' => 'Bank', 'type' => 'bank', 'opening_balance_cents' => 100_000]);
        $fund = $entity->generalFund();
        $fund->update(['opening_balance_cents' => 100_000]);

        return [$entity, $user, $account, $fund];
    }

    private function line($entity, $account, $fund, string $kind, int $cents): array
    {
        return [[
            'money_account_id' => $account->id,
            'fund_id' => $fund->id,
            'category_id' => $entity->categories()->where('kind', $kind)->first()->id,
            'amount_cents' => $kind === 'income' ? $cents : -$cents,
        ]];
    }

    public function test_statement_adds_up(): void
    {
        [$entity, $user, $account, $fund] = $this->society();
        $post = fn ($date, $kind, $cents) => app(PostingService::class)->post(
            $entity, $user, $kind === 'income' ? TransactionType::Income : TransactionType::Expense, $date,
            $this->line($entity, $account, $fund, $kind, $cents),
        );

        $post('2026-09-20', 'income', 25_000);   // before the period
        $post('2026-10-03', 'income', 40_000);
        $post('2026-10-10', 'expense', 15_000);

        $s = app(PeriodStatement::class)->build($entity, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));

        $this->assertSame(125_000, $s['accounts']['totals']['opening']);
        $this->assertSame(40_000, $s['accounts']['totals']['receipts']);
        $this->assertSame(15_000, $s['accounts']['totals']['payments']);
        $this->assertSame(150_000, $s['accounts']['totals']['closing']);
        $this->assertSame(150_000, $s['funds']['totals']['closing']);
        $this->assertTrue($s['balanced']);

        $this->assertSame(40_000, $s['income']['period']);
        $this->assertSame(65_000, $s['income']['ytd']);       // September + October
        $this->assertSame(25_000, $s['surplus']['period']);
        $this->assertTrue($s['show_ytd']);
    }

    public function test_reversals_net_out_in_the_statement(): void
    {
        [$entity, $user, $account, $fund] = $this->society();
        $service = app(PostingService::class);

        $tx = $service->post($entity, $user, TransactionType::Expense, now()->toDateString(), $this->line($entity, $account, $fund, 'expense', 30_000));
        $service->reverse($tx, $user, 'Entered twice by mistake');

        $s = app(PeriodStatement::class)->build($entity, CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->startOfDay());

        $this->assertSame(0, $s['expenditure']['period']);
        $this->assertSame([], $s['expenditure']['lines']);
        $this->assertSame(100_000, $s['accounts']['totals']['closing']);
    }

    public function test_pdf_is_served_to_members_only(): void
    {
        [$entity, $user] = $this->society();
        $url = route('reports.statement', ['entity' => $entity->id, 'period' => '2026-10']);

        $this->actingAs($user)->get($url)->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();

        $this->actingAs($user)->get("/reports/{$entity->id}/2026-13")->assertNotFound();
    }
}