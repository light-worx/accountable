<?php

namespace App\Support;

use App\Enums\EntityRole;
use Filament\Facades\Filament;

/** What can the signed-in user do in the entity (society / circuit) they are currently working in? */
final class Access
{
    public static function role(): ?EntityRole
    {
        $entity = Filament::getTenant();
        $user = auth()->user();

        return ($entity && $user) ? $user->roleIn($entity) : null;
    }

    public static function can(string $ability): bool
    {
        return self::role()?->can($ability) ?? false;
    }
}
