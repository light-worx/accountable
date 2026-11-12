<?php

namespace App\Support;

use Filament\Forms\Components\TextInput;

final class MoneyInput
{
    /** Plain rand amount in the form state (used for entries; the service converts to cents). */
    public static function rands(string $name, string $label = 'Amount'): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->numeric()
            ->inputMode('decimal')
            ->step('0.01')
            ->prefix('R');
    }

    /** A column stored in cents: shown and edited in rands. */
    public static function cents(string $name, string $label): TextInput
    {
        return self::rands($name, $label)
            ->minValue(0)
            ->default(0)
            ->formatStateUsing(fn ($state) => $state === null ? null : $state / 100)
            ->dehydrateStateUsing(fn ($state) => Money::toCents($state));
    }
}
