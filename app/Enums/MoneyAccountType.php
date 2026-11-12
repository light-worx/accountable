<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MoneyAccountType: string implements HasLabel
{
    case Bank = 'bank';
    case Cash = 'cash';
    case PettyCash = 'petty_cash';
    case Investment = 'investment';

    public function getLabel(): string
    {
        return match ($this) {
            self::Bank => 'Bank account',
            self::Cash => 'Cash on hand',
            self::PettyCash => 'Petty cash',
            self::Investment => 'Investment',
        };
    }
}
