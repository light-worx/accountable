<?php

namespace App\Models;

use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/** Append-only. Written only by App\Services\Ledger\PostingService. */
class Transaction extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['type' => TransactionType::class, 'date' => 'date', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Posted transactions cannot be changed. Reverse the entry instead.'));
        static::deleting(fn () => throw new LogicException('Posted transactions cannot be deleted. Reverse the entry instead.'));
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function payee(): BelongsTo
    {
        return $this->belongsTo(Payee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(LedgerLine::class)->orderBy('line_no');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TransactionAttachment::class);
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_transaction_id');
    }

    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_transaction_id');
    }

    /** Net movement of money (income +, expense -, transfers 0). */
    public function netCents(): int
    {
        return (int) $this->lines->sum('amount_cents');
    }

    /** Amount moved in a transfer. */
    public function movedCents(): int
    {
        return (int) ($this->lines->where('amount_cents', '>', 0)->sum('amount_cents'));
    }
}
