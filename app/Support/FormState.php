<?php

namespace App\Support;

use BackedEnum;

final class FormState
{
    /** Form state holds an enum object when a record is being edited, and its plain value when one is being created. */
    public static function value(mixed $state): mixed
    {
        return $state instanceof BackedEnum ? $state->value : $state;
    }
}