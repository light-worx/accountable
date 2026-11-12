<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PeriodEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Period history cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Period history cannot be deleted.'));
    }
}
