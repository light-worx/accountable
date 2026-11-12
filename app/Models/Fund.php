<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Fund extends Model
{
    use LogsActivity;

    protected $fillable = ['entity_id', 'name', 'is_general', 'opening_balance_cents', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_general' => 'boolean',
            'opening_balance_cents' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(LedgerLine::class);
    }

    public function balanceCents(): int
    {
        return $this->opening_balance_cents + (int) $this->lines()->sum('amount_cents');
    }
}
