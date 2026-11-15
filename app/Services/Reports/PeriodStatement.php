<?php

namespace App\Services\Reports;

use App\Models\Category;
use App\Models\Entity;
use App\Models\Fund;
use App\Models\MoneyAccount;
use App\Services\Budgets\BudgetService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Receipts, payments, fund movements and balances for a period, straight from the ledger,
 * with the approved budget alongside when there is one.
 * All amounts are integer cents. Reversals net out automatically (they carry opposite signs).
 */
class PeriodStatement
{
    private const KEYS = ['period', 'ytd', 'budget', 'budget_ytd', 'budget_annual'];

    public function __construct(private readonly BudgetService $budgets)
    {
    }

    public function build(Entity $entity, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ytdFrom = $from->year === $to->year ? $to->startOfYear() : $from;
        $showYtd = ! $ytdFrom->isSameDay($from);

        $categories = Category::where('entity_id', $entity->id)->orderBy('sort_order')->orderBy('name')->get()->keyBy('id');
        $period = $this->movements($entity, $from, $to);
        $ytd = $showYtd ? $this->movements($entity, $ytdFrom, $to) : $period;

        // Only an approved budget is used, and only for periods inside one year.
        $budget = null;
        $approved = $from->year === $to->year ? $this->budgets->current($entity, $to->year) : null;

        if ($approved) {
            $budget = [
                'version' => $approved->version,
                'approved_at' => $approved->approved_at,
                'period' => $this->budgets->sum($approved, $from->month, $to->month),
                'ytd' => $this->budgets->sum($approved, 1, $to->month),
                'annual' => $this->budgets->sum($approved, 1, 12),
            ];
        }

        $income = $this->categoryLines($categories, $period, $ytd, 'income', $budget);
        $expenditure = $this->categoryLines($categories, $period, $ytd, 'expense', $budget);

        $surplus = [];
        foreach (self::KEYS as $key) {
            $surplus[$key] = $income[$key] - $expenditure[$key];
        }

        $accounts = $this->accountRows($entity, $from, $period, $categories);
        $funds = $this->fundRows($entity, $from, $period, $categories);

        return [
            'from' => $from,
            'to' => $to,
            'ytd_from' => $ytdFrom,
            'show_ytd' => $showYtd,
            'budget' => $budget ? ['version' => $budget['version'], 'approved_at' => $budget['approved_at']] : null,
            'income' => $income,
            'expenditure' => $expenditure,
            'surplus' => $surplus,
            'accounts' => $accounts,
            'funds' => $funds,
            'balanced' => $accounts['totals']['closing'] === $funds['totals']['closing'],
        ];
    }

    private function movements(Entity $entity, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return DB::table('ledger_lines as l')
            ->join('transactions as t', 't.id', '=', 'l.transaction_id')
            ->where('l.entity_id', $entity->id)
            ->whereBetween('t.date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('l.category_id', 'l.fund_id', 'l.money_account_id')
            ->selectRaw('l.category_id, l.fund_id, l.money_account_id, SUM(l.amount_cents) as total')
            ->get();
    }

    /** @return array<int,int> id => net cents moved before $date */
    private function balancesBefore(Entity $entity, CarbonImmutable $date, string $column): array
    {
        return DB::table('ledger_lines as l')
            ->join('transactions as t', 't.id', '=', 'l.transaction_id')
            ->where('l.entity_id', $entity->id)
            ->where('t.date', '<', $date->toDateString())
            ->groupBy("l.{$column}")
            ->selectRaw("l.{$column} as id, SUM(l.amount_cents) as total")
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->id => (int) $r->total])
            ->all();
    }

    private function sumByCategory(Collection $rows, Collection $categories, string $kind): array
    {
        $sums = [];

        foreach ($rows as $r) {
            $category = $r->category_id !== null ? ($categories[$r->category_id] ?? null) : null;

            if (! $category || $category->kind->value !== $kind) {
                continue;
            }

            $sums[$category->id] = ($sums[$category->id] ?? 0) + ($kind === 'income' ? 1 : -1) * (int) $r->total;
        }

        return $sums;
    }

    private function categoryLines(Collection $categories, Collection $period, Collection $ytd, string $kind, ?array $budget): array
    {
        $p = $this->sumByCategory($period, $categories, $kind);
        $y = $this->sumByCategory($ytd, $categories, $kind);
        $lines = [];

        foreach ($categories as $category) {
            if ($category->kind->value !== $kind) {
                continue;
            }

            $row = [
                'code' => $category->return_line_code,
                'name' => $category->name,
                'period' => $p[$category->id] ?? 0,
                'ytd' => $y[$category->id] ?? 0,
                'budget' => $budget['period'][$category->id] ?? 0,
                'budget_ytd' => $budget['ytd'][$category->id] ?? 0,
                'budget_annual' => $budget['annual'][$category->id] ?? 0,
            ];

            if (array_sum(array_map('abs', array_intersect_key($row, array_flip(self::KEYS)))) === 0) {
                continue;
            }

            $lines[] = $row;
        }

        $totals = [];
        foreach (self::KEYS as $key) {
            $totals[$key] = array_sum(array_column($lines, $key));
        }

        return ['lines' => $lines] + $totals;
    }

    private function accountRows(Entity $entity, CarbonImmutable $from, Collection $period, Collection $categories): array
    {
        $before = $this->balancesBefore($entity, $from, 'money_account_id');
        $rows = [];

        foreach (MoneyAccount::where('entity_id', $entity->id)->orderBy('name')->get() as $account) {
            $opening = $account->opening_balance_cents + ($before[$account->id] ?? 0);
            [$receipts, $payments, $transfers] = $this->split($period->where('money_account_id', $account->id), $categories);
            $closing = $opening + $receipts - $payments + $transfers;

            if (! $account->is_active && $opening === 0 && $closing === 0 && $receipts === 0 && $payments === 0 && $transfers === 0) {
                continue;
            }

            $rows[] = ['id' => $account->id, 'type' => $account->type->value, 'name' => $account->name, 'opening' => $opening, 'receipts' => $receipts, 'payments' => $payments, 'transfers' => $transfers, 'closing' => $closing];
        }

        return ['rows' => $rows, 'totals' => $this->totals($rows)];
    }

    private function fundRows(Entity $entity, CarbonImmutable $from, Collection $period, Collection $categories): array
    {
        $before = $this->balancesBefore($entity, $from, 'fund_id');
        $rows = [];

        foreach (Fund::where('entity_id', $entity->id)->orderByDesc('is_general')->orderBy('name')->get() as $fund) {
            $opening = $fund->opening_balance_cents + ($before[$fund->id] ?? 0);
            [$receipts, $payments, $transfers] = $this->split($period->where('fund_id', $fund->id), $categories);
            $closing = $opening + $receipts - $payments + $transfers;

            if (! $fund->is_active && $opening === 0 && $closing === 0 && $receipts === 0 && $payments === 0 && $transfers === 0) {
                continue;
            }

            $rows[] = ['id' => $fund->id, 'is_general' => $fund->is_general, 'name' => $fund->name, 'opening' => $opening, 'receipts' => $receipts, 'payments' => $payments, 'transfers' => $transfers, 'closing' => $closing];
        }

        return ['rows' => $rows, 'totals' => $this->totals($rows)];
    }

    /** @return array{0:int,1:int,2:int} receipts, payments, transfers (net) */
    private function split(Collection $rows, Collection $categories): array
    {
        $receipts = $payments = $transfers = 0;

        foreach ($rows as $r) {
            $total = (int) $r->total;
            $category = $r->category_id !== null ? ($categories[$r->category_id] ?? null) : null;

            if (! $category) {
                $transfers += $total;
            } elseif ($category->kind->value === 'income') {
                $receipts += $total;
            } else {
                $payments += -$total;
            }
        }

        return [$receipts, $payments, $transfers];
    }

    private function totals(array $rows): array
    {
        $totals = [];

        foreach (['opening', 'receipts', 'payments', 'transfers', 'closing'] as $key) {
            $totals[$key] = array_sum(array_column($rows, $key));
        }

        return $totals;
    }
}