<?php

namespace App\Services\Budgets;

use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\Category;
use App\Models\Entity;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creating, approving and versioning budgets, and reading the approved budget for reports.
 * Only the treasurer can change budgets. Approved budgets are never edited: a change is a new version.
 */
class BudgetService
{
    /** The approved budget currently in force for a year, if any. Drafts are never used in reports. */
    public function current(Entity $entity, int $year): ?Budget
    {
        return Budget::where(['entity_id' => $entity->id, 'year' => $year, 'status' => 'approved'])
            ->orderByDesc('version')
            ->first();
    }

    /** @return array<int,int> category id => budgeted cents for months $fromMonth..$toMonth */
    public function sum(Budget $budget, int $fromMonth, int $toMonth): array
    {
        $sums = [];

        foreach ($budget->lines()->get() as $line) {
            $total = 0;

            for ($m = $fromMonth; $m <= $toMonth; $m++) {
                $total += (int) $line->{"m{$m}"};
            }

            if ($total !== 0) {
                $sums[$line->category_id] = $total;
            }
        }

        return $sums;
    }

    /** @param 'blank'|'actuals'|'copy' $startFrom */
    public function create(Entity $entity, User $user, int $year, string $startFrom): Budget
    {
        $this->requireTreasurer($entity, $user);

        return DB::transaction(function () use ($entity, $user, $year, $startFrom) {
            Entity::whereKey($entity->id)->lockForUpdate()->first();

            $existing = Budget::where(['entity_id' => $entity->id, 'year' => $year])->get();

            if ($existing->contains('status', 'draft')) {
                throw new BudgetException("There is already a draft budget for {$year}. Open it, or discard it first.");
            }

            if ($existing->contains('status', 'approved')) {
                throw new BudgetException("The {$year} budget is already approved. Open it and choose “Change this budget” to make a new version.");
            }

            $seed = match ($startFrom) {
                'actuals' => array_map(fn (int $cents) => Budget::spread($cents), $this->actualsFor($entity, $year - 1)),
                'copy' => $this->previousBudgetMonths($entity, $year - 1),
                default => [],
            };

            $budget = Budget::create([
                'entity_id' => $entity->id,
                'year' => $year,
                'version' => ($existing->max('version') ?? 0) + 1,
                'status' => 'draft',
                'created_by' => $user->id,
            ]);

            $this->createLines($budget, $seed);

            activity('budget')->performedOn($budget)->causedBy($user)
                ->withProperties(['year' => $year, 'version' => $budget->version, 'start_from' => $startFrom])
                ->log('budget created');

            return $budget;
        });
    }

    public function approve(Budget $budget, User $user, string $note): Budget
    {
        $entity = Entity::findOrFail($budget->entity_id);
        $this->requireTreasurer($entity, $user);

        if (mb_strlen(trim($note)) < 3) {
            throw new BudgetException('Please say who approved the budget, or at which meeting.');
        }

        return DB::transaction(function () use ($budget, $user, $note) {
            $fresh = Budget::whereKey($budget->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== 'draft') {
                throw new BudgetException('This budget has already been approved.');
            }

            Budget::where(['entity_id' => $fresh->entity_id, 'year' => $fresh->year, 'status' => 'approved'])
                ->get()
                ->each(fn (Budget $old) => $old->update(['status' => 'superseded']));

            $fresh->update([
                'status' => 'approved',
                'approved_by' => $user->id,
                'approved_at' => now(),
                'note' => trim($note),
            ]);

            activity('budget')->performedOn($fresh)->causedBy($user)
                ->withProperties(['year' => $fresh->year, 'version' => $fresh->version, 'note' => trim($note)])
                ->log('budget approved');

            return $fresh;
        });
    }

    /** Starts a new draft from the approved budget, recording why it is being changed. */
    public function newVersion(Budget $current, User $user, string $reason): Budget
    {
        $entity = Entity::findOrFail($current->entity_id);
        $this->requireTreasurer($entity, $user);

        if (mb_strlen(trim($reason)) < 5) {
            throw new BudgetException('Please say why the budget is being changed.');
        }

        return DB::transaction(function () use ($entity, $current, $user, $reason) {
            Entity::whereKey($entity->id)->lockForUpdate()->first();

            $latest = Budget::where(['entity_id' => $entity->id, 'year' => $current->year])->orderByDesc('version')->first();

            if ($latest->status === 'draft') {
                throw new BudgetException("There is already a draft budget for {$current->year}. Open it, or discard it first.");
            }

            if ($latest->status !== 'approved' || $latest->id !== $current->id) {
                throw new BudgetException('Only the latest approved budget can be changed.');
            }

            $budget = Budget::create([
                'entity_id' => $entity->id,
                'year' => $current->year,
                'version' => $latest->version + 1,
                'status' => 'draft',
                'change_reason' => trim($reason),
                'created_by' => $user->id,
            ]);

            $this->createLines($budget, $current->lines()->get()->mapWithKeys(fn (BudgetLine $l) => [$l->category_id => $l->months()])->all());

            activity('budget')->performedOn($budget)->causedBy($user)
                ->withProperties(['year' => $budget->year, 'version' => $budget->version, 'reason' => trim($reason)])
                ->log('new budget version started');

            return $budget;
        });
    }

    /** Throws a draft away. Approved budgets can never be discarded. */
    public function discard(Budget $budget, User $user): void
    {
        $entity = Entity::findOrFail($budget->entity_id);
        $this->requireTreasurer($entity, $user);

        DB::transaction(function () use ($budget, $user) {
            $fresh = Budget::whereKey($budget->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== 'draft') {
                throw new BudgetException('Only a draft budget can be discarded.');
            }

            $info = ['year' => $fresh->year, 'version' => $fresh->version];

            $fresh->lines()->get()->each(fn (BudgetLine $line) => $line->delete());
            $fresh->delete();

            activity('budget')->causedBy($user)->withProperties($info)->log('draft budget discarded');
        });
    }

    /** @param array<int,list<int>> $seed category id => 12 monthly amounts */
    private function createLines(Budget $budget, array $seed): void
    {
        $categories = Category::where('entity_id', $budget->entity_id)
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', array_keys($seed)))
            ->get();

        foreach ($categories as $category) {
            $months = $seed[$category->id] ?? array_fill(0, 12, 0);

            BudgetLine::create(
                ['budget_id' => $budget->id, 'category_id' => $category->id]
                + array_combine(array_map(fn ($m) => "m{$m}", range(1, 12)), $months)
            );
        }
    }

    /** @return array<int,int> category id => what was actually received / paid in the year (positive amounts only) */
    private function actualsFor(Entity $entity, int $year): array
    {
        $rows = DB::table('ledger_lines as l')
            ->join('transactions as t', 't.id', '=', 'l.transaction_id')
            ->join('categories as c', 'c.id', '=', 'l.category_id')
            ->where('l.entity_id', $entity->id)
            ->whereBetween('t.date', ["{$year}-01-01", "{$year}-12-31"])
            ->groupBy('l.category_id', 'c.kind')
            ->selectRaw('l.category_id, c.kind, SUM(l.amount_cents) as total')
            ->get();

        $actuals = [];

        foreach ($rows as $row) {
            $value = $row->kind === 'income' ? (int) $row->total : -((int) $row->total);

            if ($value > 0) {
                $actuals[(int) $row->category_id] = $value;
            }
        }

        return $actuals;
    }

    /** @return array<int,list<int>> category id => 12 monthly amounts, from last year's budget */
    private function previousBudgetMonths(Entity $entity, int $year): array
    {
        $previous = Budget::where(['entity_id' => $entity->id, 'year' => $year, 'status' => 'approved'])->orderByDesc('version')->first()
            ?? Budget::where(['entity_id' => $entity->id, 'year' => $year])->orderByDesc('version')->first();

        if (! $previous) {
            throw new BudgetException("There is no {$year} budget to copy.");
        }

        return $previous->lines()->get()->mapWithKeys(fn (BudgetLine $l) => [$l->category_id => $l->months()])->all();
    }

    private function requireTreasurer(Entity $entity, User $user): void
    {
        if (! $user->roleIn($entity)?->can('setup')) {
            throw new BudgetException('Only the treasurer can change budgets.');
        }
    }
}