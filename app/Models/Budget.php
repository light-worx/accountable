<?php

namespace App\Models;

use App\Enums\CategoryKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** A draft can be edited freely. Once approved it is frozen: changes mean a new version. */
class Budget extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (Budget $budget) {
            if ($budget->getOriginal('status') === 'draft') {
                return;
            }

            // The only change allowed to an approved budget is being replaced by a newer version.
            $changed = array_keys($budget->getDirty());
            $onlySuperseded = $budget->status === 'superseded' && array_diff($changed, ['status', 'updated_at']) === [];

            if (! $onlySuperseded) {
                throw new LogicException('An approved budget cannot be changed. Create a new version instead.');
            }
        });

        static::deleting(function (Budget $budget) {
            if ($budget->status !== 'draft') {
                throw new LogicException('Only a draft budget can be discarded.');
            }
        });
    }

    /** Splits an annual amount over 12 months so the months add up exactly (extra cents go to the first months). */
    public static function spread(int $annualCents): array
    {
        $base = intdiv($annualCents, 12);
        $extra = $annualCents % 12;

        return array_map(fn (int $month) => $base + ($month <= $extra ? 1 : 0), range(1, 12));
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class);
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(BudgetAssessment::class);
    }

    /** @return array{income:int, expense:int, surplus:int} annual totals */
    public function totals(): array
    {
        $income = 0;
        $expense = 0;

        foreach ($this->lines()->with('category')->get() as $line) {
            if ($line->category->kind === CategoryKind::Income) {
                $income += $line->annualCents();
            } else {
                $expense += $line->annualCents();
            }
        }

        return ['income' => $income, 'expense' => $expense, 'surplus' => $income - $expense];
    }
}