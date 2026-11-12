<?php

namespace Tests\Feature;

use App\Enums\TransactionType;
use App\Models\Entity;
use App\Models\MoneyAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ledger\LedgerVerifier;
use App\Services\Ledger\PeriodService;
use App\Services\Ledger\PostingException;
use App\Services\Ledger\PostingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    use RefreshDatabase;

    private function society(string $role = 'treasurer'): array
    {
        $entity = Entity::create(['type' => 'society', 'name' => 'Test Society']);
        $user = User::factory()->create();
        $entity->users()->attach($user->id, ['role' => $role]);

        $account = MoneyAccount::create([
            'entity_id' => $entity->id, 'name' => 'Bank', 'type' => 'bank', 'opening_balance_cents' => 100_000,
        ]);
        $fund = $entity->generalFund();
        $fund->update(['opening_balance_cents' => 100_000]);

        return [$entity, $user, $account, $fund];
    }

    private function income($entity, $account, $fund, int $cents): array
    {
        return [[
            'money_account_id' => $account->id, 'fund_id' => $fund->id,
            'category_id' => $entity->categories()->where('kind', 'income')->first()->id, 'amount_cents' => $cents,
        ]];
    }

    private function expense($entity, $account, $fund, int $cents): array
    {
        return [[
            'money_account_id' => $account->id, 'fund_id' => $fund->id,
            'category_id' => $entity->categories()->where('kind', 'expense')->first()->id, 'amount_cents' => -$cents,
        ]];
    }

    public function test_posting_income_updates_balances_and_numbers_entries(): void
    {
        [$entity, $user, $account, $fund] = $this->society();
        $service = app(PostingService::class);

        $a = $service->post($entity, $user, TransactionType::Income, now()->toDateString(), $this->income($entity, $account, $fund, 25_000));
        $b = $service->post($entity, $user, TransactionType::Income, now()->toDateString(), $this->income($entity, $account, $fund, 5_000));

        $this->assertSame('R-000001', $a->reference);
        $this->assertSame('R-000002', $b->reference);
        $this->assertSame(130_000, $account->fresh()->balanceCents());
        $this->assertSame(130_000, $fund->fresh()->balanceCents());
        $this->assertSame($entity->totalInAccountsCents(), $entity->totalInFundsCents());
        $this->assertSame($a->hash, $b->prev_hash);
    }

    public function test_database_refuses_edits_and_deletes_of_posted_figures(): void
    {
        [$entity, $user, $account, $fund] = $this->society();
        app(PostingService::class)->post($entity, $user, TransactionType::Income, now()->toDateString(), $this->income($entity, $account, $fund, 10_000));

        $this->expectException(QueryException::class);
        DB::table('ledger_lines')->update(['amount_cents' => 999_999]);
    }

    public function test_database_refuses_deletes(): void
    {
        [$entity, $user, $account, $fund] = $this->society();
        app(PostingService::class)->post($entity, $user, TransactionType::Income, now()->toDateString(), $this->income($entity, $account, $fund, 10_000));

        $this->expectException(QueryException::class);
        DB::table('transactions')->delete();
    }

    public function test_models_refuse_edits(): void
    {
        [$entity, $user, $account, $fund] = $this->society();
        $tx = app(PostingService::class)->post($entity, $user, TransactionType::Income, now()->toDateString(), $this->income($entity, $account, $fund, 10_000));

        $this->expectException(\LogicException::class);
        $tx->update(['description' => 'sneaky']);
    }

    public function test_cannot_spend_more_than_is_held(): void
    {
        [$entity, $user, $account, $fund] = $this->society();

        $this->expectException(PostingException::class);
        app(PostingService::class)->post($entity, $user, TransactionType::Expense, now()->toDateString(), $this->expense($entity, $account, $fund, 100_001));
    }

    public function test_reversal_cancels_the_original_and_cannot_be_repeated(): void
    {
        [$entity, $user, $account, $fund] = $this->society();
        $service = app(PostingService::class);
        $tx = $service->post($entity, $user, TransactionType::Expense, now()->toDateString(), $this->expense($entity, $account, $fund, 30_000));
        $this->assertSame(70_000, $account->fresh()->balanceCents());

        $reversal = $service->reverse($tx, $user, 'Captured the wrong amount');
        $this->assertSame(100_000, $account->fresh()->balanceCents());
        $this->assertSame($tx->id, $reversal->reverses_transaction_id);
        $this->assertTrue(Transaction::find($tx->id)->reversedBy()->exists());

        $this->expectException(PostingException::class);
        $service->reverse($tx, $user, 'Trying again');
    }

    public function test_transfers_between_funds_and_accounts_balance(): void
    {
        [$entity, $user, $account, $fund] = $this->society();
        $second = MoneyAccount::create(['entity_id' => $entity->id, 'name' => 'Petty cash', 'type' => 'petty_cash']);
        $service = app(PostingService::class);

        $service->post($entity, $user, TransactionType::AccountTransfer, now()->toDateString(), [
            ['money_account_id' => $account->id, 'fund_id' => $fund->id, 'amount_cents' => -20_000],
            ['money_account_id' => $second->id, 'fund_id' => $fund->id, 'amount_cents' => 20_000],
        ]);

        $this->assertSame(80_000, $account->fresh()->balanceCents());
        $this->assertSame(20_000, $second->fresh()->balanceCents());
        $this->assertSame(100_000, $fund->fresh()->balanceCents());
        $this->assertSame($entity->totalInAccountsCents(), $entity->totalInFundsCents());
    }

    public function test_closed_month_blocks_posting_until_reopened(): void
    {
        [$entity, $user, $account, $fund] = $this->society();
        $periods = app(PeriodService::class);
        $periods->close($entity, $user, now()->year, now()->month);

        try {
            app(PostingService::class)->post($entity, $user, TransactionType::Income, now()->toDateString(), $this->income($entity, $account, $fund, 1_000));
            $this->fail('Posting into a closed month should have been refused.');
        } catch (PostingException) {
            $this->assertTrue(true);
        }

        $periods->reopen($entity, $user, now()->year, now()->month, 'Late slip found');
        $tx = app(PostingService::class)->post($entity, $user, TransactionType::Income, now()->toDateString(), $this->income($entity, $account, $fund, 1_000));
        $this->assertNotNull($tx->id);
    }

    public function test_permissions(): void
    {
        [$entity, , $account, $fund] = $this->society();
        $viewer = User::factory()->create();
        $capturer = User::factory()->create();
        $entity->users()->attach($viewer->id, ['role' => 'viewer']);
        $entity->users()->attach($capturer->id, ['role' => 'capturer']);
        $service = app(PostingService::class);

        try {
            $service->post($entity, $viewer, TransactionType::Income, now()->toDateString(), $this->income($entity, $account, $fund, 1_000));
            $this->fail('Viewer must not be able to post.');
        } catch (PostingException) {
            $this->assertTrue(true);
        }

        $tx = $service->post($entity, $capturer, TransactionType::Income, now()->toDateString(), $this->income($entity, $account, $fund, 1_000));

        $this->expectException(PostingException::class); // capturers cannot reverse
        $service->reverse($tx, $capturer, 'Not allowed to do this');
    }

    public function test_verifier_passes_clean_ledger_and_detects_tampering(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Tamper simulation drops the trigger by its SQLite name.');
        }

        [$entity, $user, $account, $fund] = $this->society();
        $service = app(PostingService::class);
        $service->post($entity, $user, TransactionType::Income, now()->toDateString(), $this->income($entity, $account, $fund, 10_000));
        $service->post($entity, $user, TransactionType::Income, now()->toDateString(), $this->income($entity, $account, $fund, 20_000));

        $verifier = app(LedgerVerifier::class);
        $this->assertSame([], $verifier->verify($entity));

        // Simulate someone with raw database access removing the trigger and editing a figure.
        DB::unprepared('DROP TRIGGER ledger_lines_no_update');
        DB::table('ledger_lines')->where('amount_cents', 10_000)->update(['amount_cents' => 90_000]);

        $this->assertNotEmpty($verifier->verify($entity));
    }
}
