<?php

namespace App\Models;

use App\Enums\EntityType;
use App\Support\DefaultSetup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** A society, circuit or district. This is the Filament "tenant". Structure is synced from church.net.za. */
class Entity extends Model
{
    use LogsActivity;

    protected $fillable = [
        'type', 'parent_id', 'name', 'number', 'allow_overdrawn', 'is_active',
        'external_id', 'external_slug', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => EntityType::class,
            'allow_overdrawn' => 'boolean',
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::created(fn (Entity $entity) => DefaultSetup::apply($entity));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['type', 'parent_id', 'name', 'number', 'allow_overdrawn', 'is_active', 'external_id', 'external_slug'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function isSynced(): bool
    {
        return filled($this->external_id);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function moneyAccounts(): HasMany
    {
        return $this->hasMany(MoneyAccount::class);
    }

    public function funds(): HasMany
    {
        return $this->hasMany(Fund::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function payees(): HasMany
    {
        return $this->hasMany(Payee::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function generalFund(): ?Fund
    {
        return $this->funds()->where('is_general', true)->first();
    }

    public function totalInAccountsCents(): int
    {
        return (int) $this->moneyAccounts()->sum('opening_balance_cents') + $this->ledgerSumCents();
    }

    public function totalInFundsCents(): int
    {
        return (int) $this->funds()->sum('opening_balance_cents') + $this->ledgerSumCents();
    }

    private function ledgerSumCents(): int
    {
        return (int) LedgerLine::where('entity_id', $this->id)->sum('amount_cents');
    }
}