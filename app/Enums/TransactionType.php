<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TransactionType: string implements HasLabel, HasColor
{
    case Income = 'income';
    case Expense = 'expense';
    case AccountTransfer = 'account_transfer';
    case FundTransfer = 'fund_transfer';

    public function getLabel(): string
    {
        return match ($this) {
            self::Income => 'Money in',
            self::Expense => 'Money out',
            self::AccountTransfer => 'Moved between accounts',
            self::FundTransfer => 'Moved between funds',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Income => 'success',
            self::Expense => 'danger',
            default => 'info',
        };
    }

    public function referencePrefix(): string
    {
        return match ($this) {
            self::Income => 'R',
            self::Expense => 'P',
            default => 'T',
        };
    }

    public function isTransfer(): bool
    {
        return in_array($this, [self::AccountTransfer, self::FundTransfer], true);
    }
}
