<?php

namespace App\Services\Reports;

use App\Enums\EntityType;
use App\Models\Entity;
use App\Services\Budgets\BudgetService;
use App\Services\Budgets\SocietyContacts;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * What each society should have paid the circuit (from the approved budget's schedule) against what it has paid
 * (receipts on the assessment line, 3.1, attributed to that society). Balance: negative = owing, positive = paid ahead.
 */
class AssessmentStatements
{
    public function __construct(
        private readonly BudgetService $budgets,
        private readonly SocietyContacts $contacts,
    ) {
    }

    /** @return array|null null when the circuit has no approved budget for the year */
    public function build(Entity $circuit, int $year, int $month): ?array
    {
        if ($circuit->type !== EntityType::Circuit) {
            throw new InvalidArgumentException('Assessment statements are produced by circuits.');
        }

        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException('The month must be between 1 and 12.');
        }

        $budget = $this->budgets->current($circuit, $year);

        if (! $budget) {
            return null;
        }

        $schedule = $budget->assessments()->orderBy('society_name')->get();
        $known = $schedule->pluck('society_external_id')->map(fn ($id) => (string) $id)->all();

        $received = [];      // society id => 12 monthly amounts
        $monthList = [];     // society id => payments received in the chosen month
        $unattributed = 0;
        $unscheduled = [];   // society id => name and amount

        foreach ($this->receipts($circuit, $year) as $r) {
            $date = CarbonImmutable::parse($r->date);

            if ($date->month > $month) {
                continue;
            }

            $amount = (int) $r->amount_cents;
            $id = $r->society_external_id !== null ? (string) $r->society_external_id : null;

            if ($id === null) {
                $unattributed += $amount;

                continue;
            }

            if (! in_array($id, $known, true)) {
                $unscheduled[$id]['name'] = $r->payee_name;
                $unscheduled[$id]['amount'] = ($unscheduled[$id]['amount'] ?? 0) + $amount;

                continue;
            }

            $received[$id] ??= array_fill(0, 12, 0);
            $received[$id][$date->month - 1] += $amount;

            if ($date->month === $month) {
                $monthList[$id][] = ['date' => $date, 'reference' => $r->reference, 'description' => $r->description, 'amount' => $amount];
            }
        }

        $carried = $this->carriedForward($circuit, $year);
        $societies = [];

        foreach ($schedule as $row) {
            $id = (string) $row->society_external_id;
            $due = $row->months();
            $rec = $received[$id] ?? array_fill(0, 12, 0);
            $opening = $carried[$id] ?? 0;

            $grid = [];
            $running = $opening;

            for ($m = 1; $m <= $month; $m++) {
                $running += $rec[$m - 1] - $due[$m - 1];
                $grid[] = [
                    'number' => $m,
                    'month' => CarbonImmutable::create($year, $m, 1)->format('F'),
                    'due' => $due[$m - 1],
                    'received' => $rec[$m - 1],
                    'balance' => $running,
                ];
            }

            $ytdDue = array_sum(array_slice($due, 0, $month));
            $ytdReceived = array_sum(array_slice($rec, 0, $month));

            $societies[] = [
                'external_id' => $id,
                'name' => $row->society_name,
                'emails' => $this->contacts->emailsFor($circuit, $id, $row->society_email),
                'annual' => $row->annualCents(),
                'brought_forward' => $opening,
                'month_due' => $due[$month - 1],
                'month_received' => $rec[$month - 1],
                'ytd_due' => $ytdDue,
                'ytd_received' => $ytdReceived,
                'balance' => $opening + $ytdReceived - $ytdDue,
                'grid' => $grid,
                'receipts' => $monthList[$id] ?? [],
            ];
        }

        $totals = [];
        foreach (['annual', 'brought_forward', 'month_due', 'month_received', 'ytd_due', 'ytd_received', 'balance'] as $key) {
            $totals[$key] = array_sum(array_column($societies, $key));
        }

        $issues = [];

        if ($unattributed !== 0) {
            $issues[] = ['level' => 'warn', 'text' => Money::format($unattributed) . ' received as assessments is not attributed to a society, so it is not on any society’s statement.'];
        }

        foreach ($unscheduled as $info) {
            $issues[] = ['level' => 'warn', 'text' => Money::format($info['amount']) . ' was received from ' . ($info['name'] ?? 'a society') . ', who is not in the assessment schedule.'];
        }

        $monthStart = CarbonImmutable::create($year, $month, 1);

        return [
            'year' => $year,
            'month' => $month,
            'month_label' => $monthStart->format('F Y'),
            'as_at' => $monthStart->endOfMonth()->startOfDay(),
            'budget' => ['version' => $budget->version, 'approved_at' => $budget->approved_at],
            'societies' => $societies,
            'totals' => $totals,
            'unattributed' => $unattributed,
            'unscheduled' => array_values($unscheduled),
            'issues' => $issues,
        ];
    }

    /** Receipts on the assessment line (3.1) for the year, with the society (if any) each came from. */
    private function receipts(Entity $circuit, int $year): Collection
    {
        return DB::table('ledger_lines as l')
            ->join('transactions as t', 't.id', '=', 'l.transaction_id')
            ->join('categories as c', 'c.id', '=', 'l.category_id')
            ->leftJoin('payees as p', 'p.id', '=', 't.payee_id')
            ->where('l.entity_id', $circuit->id)
            ->where('c.kind', 'income')
            ->where('c.return_line_code', '3.1')
            ->whereBetween('t.date', ["{$year}-01-01", "{$year}-12-31"])
            ->orderBy('t.date')
            ->orderBy('t.id')
            ->get(['l.amount_cents', 't.date', 't.reference', 't.description', 't.payee_id', 'p.society_external_id', 'p.name as payee_name']);
    }

    /**
     * What each society owed (negative) or had paid ahead (positive) at the start of the year, from earlier
     * years that had an approved budget.
     *
     * @return array<string,int>
     */
    private function carriedForward(Entity $circuit, int $year): array
    {
        if ($year <= 2000) {
            return [];
        }

        $previous = $this->budgets->current($circuit, $year - 1);

        if (! $previous) {
            return [];
        }

        $balance = $this->carriedForward($circuit, $year - 1);
        $received = [];

        foreach ($this->receipts($circuit, $year - 1) as $r) {
            if ($r->society_external_id !== null) {
                $id = (string) $r->society_external_id;
                $received[$id] = ($received[$id] ?? 0) + (int) $r->amount_cents;
            }
        }

        foreach ($previous->assessments()->get() as $row) {
            $id = (string) $row->society_external_id;
            $balance[$id] = ($balance[$id] ?? 0) + ($received[$id] ?? 0) - $row->annualCents();
            unset($received[$id]);
        }

        foreach ($received as $id => $cents) {
            $balance[$id] = ($balance[$id] ?? 0) + $cents;
        }

        return $balance;
    }
}