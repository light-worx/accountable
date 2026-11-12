<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum EntityType: string implements HasLabel
{
    case Society = 'society';
    case Circuit = 'circuit';
    case District = 'district';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }
}
