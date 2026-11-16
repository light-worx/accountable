<?php

namespace Tests\Feature;

use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\Entity;
use App\Models\MoneyAccount;
use App\Models\User;
use App\Services\Budgets\BudgetService;
use App\Services\Ledger\PostingException;
use App\Services\Ledger\PostingService;
use App\Services\Reports\ReturnSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TypeListTest extends TestCase
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

    private function record($entity, $user, $account, $fund, string $name, int $cents, string $date = '2026-03-05'): void
    {
        $category = $entity->categories()->where('name', $name)->firstOrFail();
        $income = $category->kind->value === 'income';

        app(PostingService::class)->post(
            $entity, $user, $income ? TransactionType::Income : TransactionType::Expense, $date,
            [[
                'money_account_id' => $account->id, 'fund_id' => $fund->id, 'category_id' => $category->id,
                'amount_cents' => $income ? $cents : -$cents,
            ]],
        );
    }

    public function test_only_the_common_types_start_switched_on(): void
    {
        [$society] = $this->society();
        $circuit = Entity::create(['type' => 'circuit', 'name' => 'Test Circuit']);

        foreach ([$society, $circuit] as $entity) {
            $total = $entity->categories()->count();
            $on = $entity->categories()->where('is_active', true)->count();

            $this->assertGreaterThan(0, $on);
            $this->assertLessThan($total / 2 + 8, $on);   // a good deal shorter than the full standard list
            $this->assertLessThan($total, $on);
            $this->assertSame(0, $entity->categories()->whereNull('return_line_code')->count()); // all still linked to the form
        }

        $this->assertTrue((bool) $society->categories()->where('name', 'Sunday offerings (cash collections)')->value('is_active'));
        $this->assertFalse((bool) $society->categories()->where('name', 'M & E Fund grant')->value('is_active'));

        $districts = Entity::create(['type' => 'district', 'name' => 'A District']);
        $this->assertSame(0, $districts->categories()->where('is_active', false)->count()); // no common list: all on
    }

    public function test_a_switched_off_type_cannot_be_used_until_it_is_switched_on(): void
    {
        [$entity, $user, $account, $fund] = $this->society();

        try {
            $this->record($entity, $user, $account, $fund, 'M & E Fund grant', 5_000);
            $this->fail('A switched-off type must not be usable.');
        } catch (PostingException) {
            $this->assertTrue(true);
        }

        $entity->categories()->where('name', 'M & E Fund grant')->first()->update(['is_active' => true]);
        $this->record($entity, $user, $account, $fund, 'M & E Fund grant', 5_000);

        $this->assertSame(1, $entity->transactions()->count());
    }

    public function test_tidy_switches_off_only_unused_standard_types(): void
    {
        [$entity, $user, $account, $fund] = $this->society();

        // An older entity: everything switched on, one uncommon type used, plus one of the treasurer's own types.
        $entity->categories()->update(['is_active' => true]);
        $this->record($entity, $user, $account, $fund, 'M & E Fund grant', 5_000);
        Category::create(['entity_id' => $entity->id, 'kind' => 'income', 'name' => 'Hall hire', 'return_line_code' => '3.5.1', 'is_active' => true]);

        $this->artisan('categories:tidy --dry-run')->assertSuccessful();
        $this->assertSame(0, $entity->categories()->where('is_active', false)->count()); // dry run changes nothing

        $this->artisan('categories:tidy')->assertSuccessful();

        $active = fn (string $name) => (bool) $entity->categories()->where('name', $name)->value('is_active');

        $this->assertTrue($active('Sunday offerings (cash collections)')); // common
        $this->assertTrue($active('M & E Fund grant'));                    // uncommon but used
        $this->assertTrue($active('Hall hire'));                           // the treasurer's own
        $this->assertFalse($active('MCO Mission Resource Fund'));          // uncommon and never used
    }

    public function test_a_draft_budget_picks_up_types_switched_on_later(): void
    {
        [$entity, $user] = $this->society();
        $service = app(BudgetService::class);

        $budget = $service->create($entity, $user, 2026, 'blank');
        $type = $entity->categories()->where('name', 'M & E Fund grant')->first();
        $this->assertFalse($budget->lines()->where('category_id', $type->id)->exists());

        $type->update(['is_active' => true]);
        $service->ensureLines($budget);
        $this->assertTrue($budget->lines()->where('category_id', $type->id)->exists());

        $service->ensureLines($budget); // idempotent
        $this->assertSame(1, $budget->lines()->where('category_id', $type->id)->count());
    }

    public function test_the_guide_shows_what_made_up_a_line_when_several_types_feed_it(): void
    {
        [$entity, $user, $account, $fund] = $this->society();

        Category::create(['entity_id' => $entity->id, 'kind' => 'income', 'name' => 'Hall hire', 'return_line_code' => '3.5.1', 'is_active' => true]);
        $this->record($entity, $user, $account, $fund, 'Rental income (hall, manse)', 6_000);
        $this->record($entity, $user, $account, $fund, 'Hall hire', 4_000);
        $this->record($entity, $user, $account, $fund, 'Sunday offerings (cash collections)', 20_000);

        $d = app(ReturnSchedule::class)->build($entity, 2026);
        $rows = collect($d['income']['rows'])->where('type', 'line');

        $rental = $rows->firstWhere('code', '3.5.1');
        $this->assertSame(10_000, $rental['amount']);
        $this->assertEqualsCanonicalizing(['Rental income (hall, manse)', 'Hall hire'], array_column($rental['sources'], 'name'));

        $this->assertSame([], $rows->firstWhere('code', '3.2.2')['sources']); // one type only: nothing extra shown
    }
}