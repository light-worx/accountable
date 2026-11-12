<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PayeeType: string implements HasLabel
{
    case Supplier = 'supplier';
    case Clergy = 'clergy';
    case Organisation = 'organisation';
    case Person = 'person';

    public function getLabel(): string
    {
        return match ($this) {
            self::Supplier => 'Supplier / business',
            self::Clergy => 'Minister / pastoral staff',
            self::Organisation => 'Church body / organisation',
            self::Person => 'Other person',
        };
    }
}
