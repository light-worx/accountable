<?php

namespace App\Services\Budgets;

use App\Enums\EntityType;
use App\Models\Budget;
use App\Models\BudgetAssessment;
use App\Models\BudgetLine;
use App\Models\Category;
use App\Models\Entity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creating, approving and versioning budgets, per-society assessments for circuits, and reading the
 * approved budget for reports. Only the treasurer can change budgets. Approved budgets are never edited:
 * a change is a new version.
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

    /** The income type that holds assessments received from societies (line 3.1). Circuits only. */
    public function assessmentCategory(Entity $entity): ?Category
    {
        if ($entity->type !== EntityType::Circuit) {
            return null;
        }

        return Category::where('entity_id', $entity->id)
            ->where('kind', 'income')
            ->where('return_line_code', '3.1')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->first();
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

            $previous = $startFrom === 'copy' ? $this->previousBudget($entity, $year - 1) : null;

            $seed = match ($startFrom) {
                'actuals' => array_map(fn (int $cents) => Budget::spread($cents), $this->actualsFor($entity, $year - 1)),
                'copy' => $this->monthsOf($previous),
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

            if ($previous) {
                $this->copyAssessments($previous, $budget);
                $this->syncAssessmentLine($budget);
            }

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

            $this->createLines($budget, $this->monthsOf($current));
            $this->copyAssessments($current, $budget);
            $this->syncAssessmentLine($budget);

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

            $fresh->assessments()->get()->each(fn (BudgetAssessment $row) => $row->delete());
            $fresh->lines()->get()->each(fn (BudgetLine $line) => $line->delete());
            $fresh->delete();

            activity('budget')->causedBy($user)->withProperties($info)->log('draft budget discarded');
        });
    }

    // ---- Per-society assessments (circuit budgets) ----

    /**
     * Adds societies that are not already in the budget, with zero amounts. Nothing existing is changed.
     *
     * @param  list<array{id:string,slug:?string,name:string,email:?string}>  $societies
     * @return int how many were added
     */
    public function addSocieties(Budget $budget, User $user, array $societies): int
    {
        $this->requireDraftTreasurer($budget, $user);

        $entity = Entity::findOrFail($budget->entity_id);

        if (! $this->assessmentCategory($entity)) {
            throw new BudgetException('This circuit has no income type linked to line 3.1 (assessments received). Link one under Setup → Income & expense types first.');
        }

        $added = 0;

        DB::transaction(function () use ($budget, $societies, &$added) {
            $existing = $budget->assessments()->pluck('society_external_id')->map(fn ($id) => (string) $id)->all();

            foreach ($societies as $society) {
                $id = (string) $society['id'];

                if (in_array($id, $existing, true)) {
                    continue;
                }

                BudgetAssessment::create([
                    'budget_id' => $budget->id,
                    'society_external_id' => $id,
                    'society_slug' => $society['slug'] ?? null,
                    'society_name' => $society['name'],
                    'society_email' => $society['email'] ?? null,
                ]);

                $existing[] = $id;
                $added++;
            }

            $this->syncAssessmentLine($budget);
        });
        // Every society is also made available to record payments against (existing entries are left alone).
        app(SocietyPayees::class)->ensure($entity, $societies);
        if ($added > 0) {
            activity('budget')->performedOn($budget)->causedBy($user)
                ->withProperties(['added' => $added])->log('societies added to the assessment schedule');
        }

        return $added;
    }

    /** @param list<int> $months 12 monthly amounts in cents */
    public function setAssessment(Budget $budget, User $user, string $societyExternalId, array $months): void
    {
        $this->requireDraftTreasurer($budget, $user);

        $months = array_values($months);

        if (count($months) !== 12) {
            throw new InvalidArgumentException('An assessment needs exactly 12 monthly amounts.');
        }

        if (min($months) < 0) {
            throw new BudgetException('Amounts cannot be negative.');
        }

        DB::transaction(function () use ($budget, $user, $societyExternalId, $months) {
            $row = BudgetAssessment::where(['budget_id' => $budget->id, 'society_external_id' => $societyExternalId])->first()
                ?? throw new BudgetException('That society is not in this budget.');

            $row->update($this->monthColumns($months));
            $this->syncAssessmentLine($budget);

            activity('budget')->performedOn($budget)->causedBy($user)
                ->withProperties(['society' => $row->society_name, 'annual' => array_sum($months)])
                ->log('society assessment set');
        });
    }

    public function removeAssessment(Budget $budget, User $user, string $societyExternalId): void
    {
        $this->requireDraftTreasurer($budget, $user);

        DB::transaction(function () use ($budget, $user, $societyExternalId) {
            $row = BudgetAssessment::where(['budget_id' => $budget->id, 'society_external_id' => $societyExternalId])->first()
                ?? throw new BudgetException('That society is not in this budget.');

            $name = $row->society_name;
            $row->delete();
            $this->syncAssessmentLine($budget);

            activity('budget')->performedOn($budget)->causedBy($user)
                ->withProperties(['society' => $name])->log('society removed from the assessment schedule');
        });
    }

    /** Makes the 3.1 line the total of the society rows, month by month. Leaves it alone when there are no rows. */
    private function syncAssessmentLine(Budget $budget): void
    {
        $category = $this->assessmentCategory(Entity::findOrFail($budget->entity_id));
        $rows = $budget->assessments()->get();

        if (! $category || $rows->isEmpty()) {
            return;
        }

        $sums = array_fill(0, 12, 0);

        foreach ($rows as $row) {
            foreach ($row->months() as $i => $cents) {
                $sums[$i] += $cents;
            }
        }

        $line = BudgetLine::firstOrNew(['budget_id' => $budget->id, 'category_id' => $category->id]);
        $line->fill($this->monthColumns($sums))->save();
    }

    private function copyAssessments(Budget $from, Budget $to): void
    {
        $keys = array_merge(
            ['society_external_id', 'society_slug', 'society_name', 'society_email'],
            array_map(fn ($m) => "m{$m}", range(1, 12)),
        );

        foreach ($from->assessments()->get() as $row) {
            BudgetAssessment::create(['budget_id' => $to->id] + $row->only($keys));
        }
    }

    // ---- Helpers ----

    /** @param array<int,list<int>> $seed category id => 12 monthly amounts */
    private function createLines(Budget $budget, array $seed): void
    {
        $categories = Category::where('entity_id', $budget->entity_id)
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', array_keys($seed)))
            ->get();

        foreach ($categories as $category) {
            BudgetLine::create(
                ['budget_id' => $budget->id, 'category_id' => $category->id]
                + $this->monthColumns($seed[$category->id] ?? array_fill(0, 12, 0))
            );
        }
    }

        /** Adds a zero line for any type switched on since the draft was started. */
    public function ensureLines(Budget $budget): void
    {
        if ($budget->status !== 'draft') {
            return;
        }

        $have = $budget->lines()->pluck('category_id')->all();

        Category::where('entity_id', $budget->entity_id)
            ->where('is_active', true)
            ->whereNotIn('id', $have)
            ->get()
            ->each(fn (Category $c) => BudgetLine::create(
                ['budget_id' => $budget->id, 'category_id' => $c->id] + $this->monthColumns(array_fill(0, 12, 0))
            ));
    }

    /** @return array<string,int> m1..m12 => cents */
    private function monthColumns(array $months): array
    {
        return array_combine(array_map(fn ($m) => "m{$m}", range(1, 12)), array_values($months));
    }

    /** @return array<int,list<int>> category id => 12 monthly amounts */
    private function monthsOf(Budget $budget): array
    {
        return $budget->lines()->get()->mapWithKeys(fn (BudgetLine $l) => [$l->category_id => $l->months()])->all();
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

    private function previousBudget(Entity $entity, int $year): Budget
    {
        return Budget::where(['entity_id' => $entity->id, 'year' => $year, 'status' => 'approved'])->orderByDesc('version')->first()
            ?? Budget::where(['entity_id' => $entity->id, 'year' => $year])->orderByDesc('version')->first()
            ?? throw new BudgetException("There is no {$year} budget to copy.");
    }

    private function requireTreasurer(Entity $entity, User $user): void
    {
        if (! $user->roleIn($entity)?->can('setup')) {
            throw new BudgetException('Only the treasurer can change budgets.');
        }
    }

    private function requireDraftTreasurer(Budget $budget, User $user): void
    {
        $this->requireTreasurer(Entity::findOrFail($budget->entity_id), $user);

        if (Budget::whereKey($budget->id)->value('status') !== 'draft') {
            throw new BudgetException('This budget is approved, so it cannot be changed. Make a new version first.');
        }
    }
}