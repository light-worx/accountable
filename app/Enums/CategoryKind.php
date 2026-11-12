<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CategoryKind: string implements HasLabel, HasColor
{
    case Income = 'income';
    case Expense = 'expense';

    public function getLabel(): string
    {
        return $this === self::Income ? 'Money in' : 'Money out';
    }

    public function getColor(): string
    {
        return $this === self::Income ? 'success' : 'danger';
    }
}
