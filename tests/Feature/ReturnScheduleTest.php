<?php

namespace Tests\Feature;

use App\Enums\TransactionType;
use App\Models\Entity;
use App\Models\MoneyAccount;
use App\Models\Payee;
use App\Models\User;
use App\Services\Ledger\PostingService;
use App\Services\Reports\ReturnSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReturnScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function entity(string $type): array
    {
        $entity = Entity::create(['type' => $type, 'name' => 'Test ' . ucfirst($type)]);
        $user = User::factory()->create();
        $entity->users()->attach($user->id, ['role' => 'treasurer']);

        $account = MoneyAccount::create(['entity_id' => $entity->id, 'name' => 'Bank', 'type' => 'bank', 'opening_balance_cents' => 100_000]);
        $fund = $entity->generalFund();
        $fund->update(['opening_balance_cents' => 100_000]);

        return [$entity, $user, $account, $fund];
    }

    private function pay($entity, $user, $account, $fund, string $name, int $cents, string $date, ?int $payeeId = null): void
    {
        $category = $entity->categories()->where('name', $name)->firstOrFail();
        $category->update(['is_active' => true]);
        $income = $category->kind->value === 'income';

        app(PostingService::class)->post(
            $entity, $user, $income ? TransactionType::Income : TransactionType::Expense, $date,
            [[
                'money_account_id' => $account->id, 'fund_id' => $fund->id, 'category_id' => $category->id,
                'amount_cents' => $income ? $cents : -$cents,
            ]],
            null, $payeeId,
        );
    }

    public function test_default_types_arrive_linked_to_their_return_lines(): void
    {
        [$society] = $this->entity('society');
        [$circuit] = $this->entity('circuit');

        $this->assertSame('3.2.2', $society->categories()->where('name', 'Sunday offerings (cash collections)')->value('return_line_code'));
        $this->assertSame('4.4.2', $society->categories()->where('name', 'Electricity, water, rates & rent of premises')->value('return_line_code'));
        $this->assertSame(0, $society->categories()->whereNull('return_line_code')->count());

        $this->assertSame('3.1', $circuit->categories()->where('name', 'Assessments received from societies')->value('return_line_code'));
        $this->assertSame('4.4.2', $circuit->categories()->where('name', 'Circuit assessments (to the MCO)')->value('return_line_code'));
        $this->assertSame(0, $circuit->categories()->whereNull('return_line_code')->count());
    }

    public function test_society_figures_subtotals_and_section_8_add_up(): void
    {
        [$entity, $user, $account, $fund] = $this->entity('society');
        $minister = Payee::create(['entity_id' => $entity->id, 'name' => 'Rev A Minister', 'type' => 'clergy', 'pf_number' => '12345']);

        $this->pay($entity, $user, $account, $fund, 'Sunday offerings (cash collections)', 100_000, '2026-03-05');
        $this->pay($entity, $user, $account, $fund, 'Other income', 5_000, '2026-04-05');
        $this->pay($entity, $user, $account, $fund, 'Pastoral staff salaries', 60_000, '2026-05-05', $minister->id);
        $this->pay($entity, $user, $account, $fund, 'Electricity, water, rates & rent of premises', 12_000, '2026-05-10');
        $this->pay($entity, $user, $account, $fund, 'Other sundry payments', 3_000, '2026-05-12');

        $d = app(ReturnSchedule::class)->build($entity, 2026);

        $this->assertSame('4S', $d['form']['code']);
        $this->assertSame(105_000, $d['income']['total']);
        $this->assertSame(75_000, $d['expenditure']['total']);
        $this->assertSame(30_000, $d['surplus']);

        $subtotal = collect($d['expenditure']['rows'])->firstWhere('label', 'Sub-total (must agree with Section 8)');
        $this->assertSame(60_000, $subtotal['amount']);
        $this->assertSame(60_000, $d['pastoral']['total']);
        $this->assertSame($d['pastoral']['reference_total'], $d['pastoral']['total']);
        $this->assertSame('12345', $d['pastoral']['rows'][0]['pf']);

        $this->assertSame(['3.7.1', '4.5.6'], array_column($d['continuation'], 'code'));
        $this->assertTrue($d['ready']);
        $this->assertSame(130_000, $d['reference']['bank']);
    }

    public function test_circuit_figures_use_the_4c_lines(): void
    {
        [$entity, $user, $account, $fund] = $this->entity('circuit');
        $minister = Payee::create(['entity_id' => $entity->id, 'name' => 'Rev C Superintendent', 'type' => 'clergy', 'pf_number' => '777']);

        $this->pay($entity, $user, $account, $fund, 'Assessments received from societies', 100_000, '2026-03-05');
        $this->pay($entity, $user, $account, $fund, 'Basic stipends', 60_000, '2026-04-05');
        $this->pay($entity, $user, $account, $fund, 'Housing allowance / rent paid to minister', 10_000, '2026-04-06', $minister->id);
        $this->pay($entity, $user, $account, $fund, 'Circuit assessments (to the MCO)', 5_000, '2026-04-07');

        $d = app(ReturnSchedule::class)->build($entity, 2026);

        $this->assertSame('4C', $d['form']['code']);
        $this->assertSame(100_000, $d['income']['total']);
        $this->assertSame(75_000, $d['expenditure']['total']);

        $stipends = collect($d['expenditure']['rows'])->where('type', 'subtotal')->first();
        $this->assertSame(70_000, $stipends['amount']); // 4.1 = basic stipend + housing allowance

        // Section 8 lists allowances only (4.1.4–4.1.6), not basic stipends
        $this->assertSame(10_000, $d['pastoral']['total']);
        $this->assertSame('777', $d['pastoral']['rows'][0]['pf']);
        $this->assertTrue($d['ready']);
    }

    public function test_renamed_types_are_not_duplicated_when_defaults_are_reapplied(): void
    {
        [$entity] = $this->entity('society');
        $before = $entity->categories()->count();

        $entity->categories()->where('name', 'Sunday offerings (cash collections)')->update(['name' => 'Sunday plate']);
        \App\Support\DefaultSetup::apply($entity);

        $this->assertSame($before, $entity->categories()->count());
        $this->assertSame(1, $entity->categories()->where('return_line_code', '3.2.2')->count());
    }

    public function test_unlinked_types_and_missing_pf_numbers_are_flagged(): void
    {
        [$entity, $user, $account, $fund] = $this->entity('society');
        $minister = Payee::create(['entity_id' => $entity->id, 'name' => 'Rev B', 'type' => 'clergy']);

        $entity->categories()->where('name', 'Other income')->update(['return_line_code' => null]);
        $this->pay($entity, $user, $account, $fund, 'Other income', 5_000, '2026-04-05');
        $this->pay($entity, $user, $account, $fund, 'Pastoral staff salaries', 10_000, '2026-05-05', $minister->id);

        $d = app(ReturnSchedule::class)->build($entity, 2026);
        $texts = implode(' ', array_column($d['issues'], 'text'));

        $this->assertFalse($d['ready']);
        $this->assertStringContainsString('Other income', $texts);
        $this->assertStringContainsString('No PF number recorded for Rev B', $texts);
        $this->assertSame(0, $d['income']['total']); // unlinked amounts are not guessed into a line
    }

    public function test_guide_is_served_to_members_of_societies_and_circuits_only(): void
    {
        [$society, $user] = $this->entity('society');
        $url = route('reports.return', ['entity' => $society->id, 'year' => 2026]);

        $this->actingAs($user)->get($url)->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();

        [$circuit, $circuitUser] = $this->entity('circuit');
        $this->actingAs($circuitUser)
            ->get(route('reports.return', ['entity' => $circuit->id, 'year' => 2026]))
            ->assertOk()->assertHeader('content-type', 'application/pdf');

        $district = Entity::create(['type' => 'district', 'name' => 'A District']);
        $district->users()->attach($user->id, ['role' => 'treasurer']);
        $this->actingAs($user)->get(route('reports.return', ['entity' => $district->id, 'year' => 2026]))->assertNotFound();
    }
}