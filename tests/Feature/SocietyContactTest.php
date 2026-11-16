<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\SocietyContact;
use App\Models\User;
use App\Services\Budgets\BudgetException;
use App\Services\Budgets\BudgetService;
use App\Services\Budgets\SocietyContacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocietyContactTest extends TestCase
{
    use RefreshDatabase;

    private function circuit(string $role = 'treasurer'): array
    {
        $circuit = Entity::create(['type' => 'circuit', 'name' => 'Test Circuit', 'external_id' => '10']);
        $user = User::factory()->create();
        $circuit->users()->attach($user->id, ['role' => $role]);

        return [$circuit, $user];
    }

    public function test_the_circuits_own_entry_wins_and_clearing_it_goes_back_to_church_net_za(): void
    {
        [$circuit, $user] = $this->circuit();
        $contacts = app(SocietyContacts::class);

        $this->assertSame(['x@example.org'], $contacts->emailsFor($circuit, '100', 'x@example.org'));
        $this->assertSame([], $contacts->emailsFor($circuit, '100', null));

        $contacts->set($circuit, $user, '100', 'Society X', 'Treasurer@Example.org; second@example.org');
        $this->assertSame(['treasurer@example.org', 'second@example.org'], $contacts->emailsFor($circuit, '100', 'x@example.org'));
        $this->assertSame(['100' => 'treasurer@example.org, second@example.org'], $contacts->overrides($circuit));

        $contacts->set($circuit, $user, '100', 'Society X', '   ');
        $this->assertSame(['x@example.org'], $contacts->emailsFor($circuit, '100', 'x@example.org'));
        $this->assertSame(0, SocietyContact::count());
    }

    public function test_invalid_addresses_are_refused_but_bad_directory_data_is_skipped(): void
    {
        [$circuit, $user] = $this->circuit();
        $contacts = app(SocietyContacts::class);

        $this->assertSame(['ok@example.org'], $contacts->emailsFor($circuit, '100', 'bad, ok@example.org'));

        $this->expectException(BudgetException::class);
        $contacts->set($circuit, $user, '100', 'Society X', 'not-an-email');
    }

    public function test_only_the_treasurer_can_change_contact_details(): void
    {
        [$circuit] = $this->circuit();
        $capturer = User::factory()->create();
        $circuit->users()->attach($capturer->id, ['role' => 'capturer']);

        $this->expectException(BudgetException::class);
        app(SocietyContacts::class)->set($circuit, $capturer, '100', 'Society X', 'x@example.org');
    }

    public function test_contact_details_can_change_after_the_budget_is_approved_and_are_logged(): void
    {
        [$circuit, $user] = $this->circuit();
        $budgets = app(BudgetService::class);

        $budget = $budgets->create($circuit, $user, 2026, 'blank');
        $budgets->approve($budget, $user, 'Circuit Quarterly Meeting');

        app(SocietyContacts::class)->set($circuit, $user, '100', 'Society X', 'x@example.org');

        $this->assertSame(['x@example.org'], app(SocietyContacts::class)->emailsFor($circuit, '100', null));
        $this->assertDatabaseHas('activity_log', ['log_name' => 'contacts', 'subject_type' => SocietyContact::class, 'description' => 'created']);
    }

    public function test_societies_cannot_hold_contact_details(): void
    {
        $society = Entity::create(['type' => 'society', 'name' => 'A Society']);
        $user = User::factory()->create();
        $society->users()->attach($user->id, ['role' => 'treasurer']);

        $this->expectException(BudgetException::class);
        app(SocietyContacts::class)->set($society, $user, '100', 'Society X', 'x@example.org');
    }
}