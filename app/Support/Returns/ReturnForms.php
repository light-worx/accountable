<?php

namespace App\Support\Returns;

use App\Enums\EntityType;
use App\Models\Entity;

final class ReturnForms
{
    /** @return class-string<ReturnForm>|null the annual return form for this kind of entity */
    public static function for(Entity|EntityType|null $subject): ?string
    {
        $type = $subject instanceof Entity ? $subject->type : $subject;

        return match ($type) {
            EntityType::Society => Form4S::class,
            EntityType::Circuit => Form4C::class,
            default => null,
        };
    }
}