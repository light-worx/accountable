<?php

namespace Database\Seeders;

use App\Models\Entity;
use App\Models\MoneyAccount;
use App\Models\User;
use Illuminate\Database\Seeder;

/** Demo data for trying the system. DO NOT run in production (it creates a user with a known password). */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $circuit = Entity::create(['type' => 'circuit', 'name' => 'Demo Circuit', 'number' => '0001']);
        $society = Entity::create(['type' => 'society', 'parent_id' => $circuit->id, 'name' => 'Demo Society', 'number' => '0001']);

        $user = User::firstOrCreate(
            ['email' => 'treasurer@example.test'],
            ['name' => 'Demo Treasurer', 'password' => 'password'],
        );
        $society->users()->syncWithoutDetaching([$user->id => ['role' => 'treasurer']]);
        $circuit->users()->syncWithoutDetaching([$user->id => ['role' => 'viewer']]);

        MoneyAccount::create([
            'entity_id' => $society->id, 'name' => 'Cheque account', 'type' => 'bank',
            'bank_name' => 'Demo Bank', 'opening_balance_cents' => 1_000_000, 'opening_date' => now()->startOfYear(),
        ]);
        MoneyAccount::create(['entity_id' => $society->id, 'name' => 'Petty cash', 'type' => 'petty_cash']);

        // Opening money all belongs to the General Fund, so accounts and funds agree.
        $society->generalFund()->update(['opening_balance_cents' => 1_000_000]);
    }
}
