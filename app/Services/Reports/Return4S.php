<?php

namespace App\Services\Reports;

use App\Models\Category;
use App\Models\Entity;
use App\Models\Payee;
use App\Services\Ledger\PeriodService;
use App\Support\Money;
use App\Support\Returns\Form4S;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Works out the figures for a society's Schedule 4S from the ledger, plus a list of anything that needs attention. */
class Return4S
{
    /** Lines whose detail is itemised on a "specify" page. */
    private const SPECIFY = [
        '3.5.2' => 'Income page – Trading / business income: other (specify)',
        '3.7.1' => 'Page 3a – Other income',
        '4.3.7' => 'Page 4a – Other administration expenses',
        '4.4.7' => 'Page 4a – Other building, furniture & equipment expenses',
        '4.5.6' => 'Page 4b – Other sundry payments',
        '4.5.8' => 'Page 4c – Rhona expenses',
    ];

    public function __construct(
        private readonly PeriodStatement $statement,
        private readonly PeriodService $periods,
    ) {
    }

    public function build(Entity $entity, int $year): array
    {
        $from = CarbonImmutable::create($year, 1, 1)->startOfDay();
        $to = CarbonImmutable::create($year, 12, 31)->startOfDay();

        $categories = Category::where('entity_id', $entity->id)->orderBy('sort_order')->orderBy('name')->get()->keyBy('id');
        $payees = Payee::where('entity_id', $entity->id)->get()->keyBy('id');

        [$byCat, $byCatPayee] = $this->aggregate($this->sums($entity, $year), $categories);
        [$priorByCat] = $this->aggregate($this->sums($entity, $year - 1), $categories);

        $issues = [];
        $incomeCodes = $this->codeAmounts($categories, $byCat, 'income', $issues);
        $expenseCodes = $this->codeAmounts($categories, $byCat, 'expense', $issues);
        $priorIncome = $this->codeAmounts($categories, $priorByCat, 'income');
        $priorExpense = $this->codeAmounts($categories, $priorByCat, 'expense');

        $incomeTotal = array_sum($incomeCodes);
        $expenseTotal = array_sum($expenseCodes);

        // "Specify" pages
        $continuation = [];
        foreach (self::SPECIFY as $code => $title) {
            $items = [];
            foreach ($categories as $c) {
                $v = $byCat[$c->id] ?? 0;
                if ($c->return_line_code === $code && $v !== 0) {
                    $items[] = ['name' => $c->name, 'amount' => $v];
                }
            }
            if ($items) {
                $continuation[] = ['code' => $code, 'title' => $title, 'items' => $items, 'total' => array_sum(array_column($items, 'amount'))];
            }
        }

        // Gifts to clergy outside the circuit (4.5.7) and pastoral allowances (Section 8)
        $gifts = $this->payeeRows($categories, $byCatPayee, $payees, fn (string $code) => $code === '4.5.7');
        $pastoral = $this->payeeRows($categories, $byCatPayee, $payees, fn (string $code) => str_starts_with($code, '4.2.'));
        $pastoralTotal = array_sum(array_column($pastoral, 'amount'));
        $subtotal42 = array_sum(array_map(fn ($c) => $incomeCodes[$c] ?? $expenseCodes[$c] ?? 0, ['4.2.1', '4.2.2', '4.2.3', '4.2.4', '4.2.5']));

        foreach (array_merge($pastoral, $gifts) as $row) {
            if (! $row['has_payee']) {
                $issues[] = ['level' => 'error', 'text' => "A pastoral payment of " . Money::format($row['amount']) . " under {$row['code']} has no payee recorded, so it cannot be listed by person."];
            } elseif (blank($row['pf'])) {
                $issues[] = ['level' => 'warn', 'text' => "No PF number recorded for {$row['name']}."];
            }
        }

        // Funds, investments and reference figures
        $stmt = $this->statement->build($entity, $from, $to);
        $specificFunds = array_values(array_filter($stmt['funds']['rows'], fn ($r) => ! $r['is_general']));
        $generalFund = array_sum(array_map(fn ($r) => $r['is_general'] ? $r['closing'] : 0, $stmt['funds']['rows']));

        $investments = array_values(array_filter($stmt['accounts']['rows'], fn ($r) => $r['type'] === 'investment'));
        $flows = $this->investmentFlows($entity, $year, array_column($investments, 'id'));
        foreach ($investments as &$row) {
            $row['deposits'] = $flows[$row['id']]['ins'] ?? 0;
            $row['withdrawals'] = $flows[$row['id']]['outs'] ?? 0;
        }
        unset($row);

        $sumType = fn (array $types) => array_sum(array_map(
            fn ($r) => in_array($r['type'], $types, true) ? $r['closing'] : 0,
            $stmt['accounts']['rows'],
        ));

        $reference = [
            'bank' => $sumType(['bank']),
            'cash' => $sumType(['cash', 'petty_cash']),
            'investments' => $sumType(['investment']),
            'specific_funds' => array_sum(array_column($specificFunds, 'closing')),
            'general_fund' => $generalFund,
        ];

        // Readiness checks
        if (! $stmt['balanced']) {
            $issues[] = ['level' => 'error', 'text' => 'Bank and cash balances do not agree with fund balances. Check opening balances.'];
        }

        $lastMonth = $year < now()->year ? 12 : ($year === now()->year ? now()->month : 0);
        $open = [];
        for ($m = 1; $m <= $lastMonth; $m++) {
            $month = CarbonImmutable::create($year, $m, 1);
            if (! $this->periods->isClosed($entity, $month)) {
                $open[] = $month->format('M');
            }
        }
        if ($open) {
            $issues[] = ['level' => 'warn', 'text' => 'These months have not been closed yet: ' . implode(', ', $open) . '.'];
        }

        return [
            'year' => $year,
            'from' => $from,
            'to' => $to,
            'prior_has_data' => $priorIncome !== [] || $priorExpense !== [],
            'income' => ['rows' => $this->render(Form4S::incomeLayout(), $incomeCodes, $priorIncome), 'total' => $incomeTotal, 'prior' => array_sum($priorIncome)],
            'expenditure' => ['rows' => $this->render(Form4S::expenditureLayout(), $expenseCodes, $priorExpense), 'total' => $expenseTotal, 'prior' => array_sum($priorExpense)],
            'surplus' => $incomeTotal - $expenseTotal,
            'continuation' => $continuation,
            'gifts' => $gifts,
            'pastoral' => ['rows' => $pastoral, 'total' => $pastoralTotal, 'subtotal_42' => $subtotal42],
            'funds' => $specificFunds,
            'investments' => $investments,
            'reference' => $reference,
            'issues' => $issues,
            'ready' => collect($issues)->where('level', 'error')->isEmpty(),
        ];
    }

    /** One row per category and payee for the year, ledger lines only. */
    private function sums(Entity $entity, int $year): Collection
    {
        return DB::table('ledger_lines as l')
            ->join('transactions as t', 't.id', '=', 'l.transaction_id')
            ->where('l.entity_id', $entity->id)
            ->whereBetween('t.date', ["{$year}-01-01", "{$year}-12-31"])
            ->whereNotNull('l.category_id')
            ->groupBy('l.category_id', 't.payee_id')
            ->selectRaw('l.category_id, t.payee_id, SUM(l.amount_cents) as total')
            ->get();
    }

    /** @return array{0: array<int,int>, 1: array<int,array<int,int>>} amounts per category, and per category and payee (income +, expense shown positive) */
    private function aggregate(Collection $sums, Collection $categories): array
    {
        $byCat = [];
        $byCatPayee = [];

        foreach ($sums as $r) {
            $c = $categories[$r->category_id] ?? null;

            if (! $c) {
                continue;
            }

            $value = $c->kind->value === 'income' ? (int) $r->total : -((int) $r->total);
            $payee = (int) $r->payee_id;

            $byCat[$c->id] = ($byCat[$c->id] ?? 0) + $value;
            $byCatPayee[$c->id][$payee] = ($byCatPayee[$c->id][$payee] ?? 0) + $value;
        }

        return [$byCat, $byCatPayee];
    }

    /** @return array<string,int> 4S line code => amount. Anything not linked to a valid line is reported in $issues. */
    private function codeAmounts(Collection $categories, array $byCat, string $kind, ?array &$issues = null): array
    {
        $valid = $kind === 'income' ? Form4S::income() : Form4S::expenditure();
        $amounts = [];

        foreach ($categories as $c) {
            $value = $byCat[$c->id] ?? 0;

            if ($c->kind->value !== $kind || $value === 0) {
                continue;
            }

            $code = $c->return_line_code;

            if ($code === null || ! isset($valid[$code])) {
                if ($issues !== null) {
                    $issues[] = ['level' => 'error', 'text' => "“{$c->name}” (" . Money::format($value) . ') is not linked to a 4S line. Link it under Setup → Income & expense types.'];
                }

                continue;
            }

            $amounts[$code] = ($amounts[$code] ?? 0) + $value;
        }

        return $amounts;
    }

    private function render(array $layout, array $amounts, array $prior): array
    {
        $rows = [];

        foreach ($layout as $item) {
            if ($item[0] === 'h') {
                $rows[] = ['type' => 'heading', 'code' => $item[1], 'label' => $item[2]];
            } elseif ($item[0] === 'l') {
                $rows[] = [
                    'type' => 'line', 'code' => $item[1], 'label' => Form4S::label($item[1]),
                    'amount' => $amounts[$item[1]] ?? 0, 'prior' => $prior[$item[1]] ?? 0,
                ];
            } else {
                $rows[] = [
                    'type' => 'subtotal', 'label' => $item[1],
                    'amount' => array_sum(array_map(fn ($c) => $amounts[$c] ?? 0, $item[2])),
                    'prior' => array_sum(array_map(fn ($c) => $prior[$c] ?? 0, $item[2])),
                ];
            }
        }

        return $rows;
    }

    private function payeeRows(Collection $categories, array $byCatPayee, Collection $payees, callable $filter): array
    {
        $rows = [];

        foreach ($categories as $c) {
            if ($c->return_line_code === null || ! $filter($c->return_line_code)) {
                continue;
            }

            foreach ($byCatPayee[$c->id] ?? [] as $payeeId => $amount) {
                if ($amount === 0) {
                    continue;
                }

                $payee = $payeeId ? ($payees[$payeeId] ?? null) : null;

                $rows[] = [
                    'code' => $c->return_line_code,
                    'name' => $payee?->name ?? '(no payee recorded)',
                    'pf' => $payee?->pf_number,
                    'description' => $c->name,
                    'amount' => $amount,
                    'has_payee' => $payee !== null,
                ];
            }
        }

        usort($rows, fn ($a, $b) => [$a['name'], $a['code']] <=> [$b['name'], $b['code']]);

        return $rows;
    }

    /** @return array<int,array{ins:int,outs:int}> */
    private function investmentFlows(Entity $entity, int $year, array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }

        return DB::table('ledger_lines as l')
            ->join('transactions as t', 't.id', '=', 'l.transaction_id')
            ->where('l.entity_id', $entity->id)
            ->whereIn('l.money_account_id', $accountIds)
            ->whereBetween('t.date', ["{$year}-01-01", "{$year}-12-31"])
            ->groupBy('l.money_account_id')
            ->selectRaw('l.money_account_id as id, SUM(CASE WHEN l.amount_cents > 0 THEN l.amount_cents ELSE 0 END) as ins, SUM(CASE WHEN l.amount_cents < 0 THEN -l.amount_cents ELSE 0 END) as outs')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->id => ['ins' => (int) $r->ins, 'outs' => (int) $r->outs]])
            ->all();
    }
}