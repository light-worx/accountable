<?php

namespace App\Models;

use App\Enums\MoneyAccountType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class MoneyAccount extends Model
{
    use LogsActivity;

    protected $fillable = ['entity_id', 'name', 'type', 'bank_name', 'account_number', 'opening_balance_cents', 'opening_date', 'is_active'];

    protected function casts(): array
    {
        return [
            'type' => MoneyAccountType::class,
            'opening_date' => 'date',
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
