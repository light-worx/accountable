<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\MoneyAccount;
use App\Models\User;
use App\Services\Ledger\PeriodService;
use App\Services\Ledger\PostingException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonthEndTest extends TestCase
{
    use RefreshDatabase;

    private function circuit(): array
    {
        $circuit = Entity::create(['type' => 'circuit', 'name' => 'Test Circuit']);
        $user = User::factory()->create();
        $circuit->users()->attach($user->id, ['role' => 'treasurer']);

        return [$circuit, $user];
    }

    public function test_months_can_be_closed_and_reopened_with_a_reason(): void
    {
        [$circuit, $user] = $this->circuit();
        $periods = app(PeriodService::class);

        $periods->close($circuit, $user, 2026, 3);
        $this->assertSame(['2026-03'], $periods->closedMonths($circuit));

        try {
            $periods->close($circuit, $user, 2026, 3);
            $this->fail('A closed month cannot be closed again.');
        } catch (PostingException) {
            $this->assertTrue(true);
        }

        try {
            $periods->reopen($circuit, $user, 2026, 4, 'This month was never closed');
            $this->fail('Only a closed month can be reopened.');
        } catch (PostingException) {
            $this->assertTrue(true);
        }

        $periods->reopen($circuit, $user, 2026, 3, 'Late slip found');
        $this->assertSame([], $periods->closedMonths($circuit));
    }

    public function test_a_month_cannot_be_closed_while_the_books_do_not_balance(): void
    {
        [$circuit, $user] = $this->circuit();
        MoneyAccount::create(['entity_id' => $circuit->id, 'name' => 'Bank', 'type' => 'bank', 'opening_balance_cents' => 5_000]); // funds still 0

        $this->expectException(PostingException::class);
        app(PeriodService::class)->close($circuit, $user, 2026, 3);
    }
}