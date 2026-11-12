<?php

namespace App\Filament\Widgets;

use App\Support\Money;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class MoneyBalances extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $entity = Filament::getTenant();

        if (! $entity) {
            return [];
        }

        $inAccounts = $entity->totalInAccountsCents();
        $inFunds = $entity->totalInFundsCents();
        $agree = $inAccounts === $inFunds;

        return [
            Stat::make('Money held (bank, cash & investments)', Money::format($inAccounts)),
            Stat::make('Money held in funds', Money::format($inFunds)),
            Stat::make('Do they agree?', $agree ? 'Yes' : 'No – check opening balances')
                ->description($agree ? 'Everything balances' : 'Difference: ' . Money::format($inAccounts - $inFunds))
                ->descriptionIcon($agree ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedExclamationTriangle)
                ->color($agree ? 'success' : 'danger'),
        ];
    }
}