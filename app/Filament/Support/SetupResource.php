<?php

namespace App\Filament\Support;

use App\Support\Access;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Base for the simple "Setup" lists: anyone can look, only the treasurer can add/change, nobody can delete (switch off instead). */
abstract class SetupResource extends Resource
{
    protected static string|UnitEnum|null $navigationGroup = 'Setup';

    public static function canCreate(): bool
    {
        return Access::can('setup');
    }

    public static function canEdit(Model $record): bool
    {
        return Access::can('setup');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
