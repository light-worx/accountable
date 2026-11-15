<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class BudgetLine extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (BudgetLine $line) {
            if ($line->budget->status !== 'draft') {
                throw new LogicException('An approved budget cannot be changed. Create a new version instead.');
            }
        });

        static::deleting(function (BudgetLine $line) {
            if ($line->budget->status !== 'draft') {
                throw new LogicException('An approved budget cannot be changed. Create a new version instead.');
            }
        });
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return list<int> the 12 monthly amounts in cents */
    public function months(): array
    {
        return array_map(fn (int $m) => (int) $this->{"m{$m}"}, range(1, 12));
    }

    public function annualCents(): int
    {
        return array_sum($this->months());
    }

    /** True when the months are an even spread (within a cent). */
    public function isEven(): bool
    {
        $months = $this->months();

        return max($months) - min($months) <= 1;
    }
}