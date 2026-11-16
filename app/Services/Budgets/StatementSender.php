<?php

namespace App\Services\Budgets;

use App\Enums\EntityType;
use App\Jobs\SendAssessmentStatement;
use App\Models\Entity;
use App\Models\StatementSend;
use App\Models\User;
use App\Services\Ledger\PeriodService;
use App\Services\Reports\AssessmentStatements;
use Carbon\CarbonImmutable;

/** Works out who should get a statement, and queues the emails. Only the treasurer can send, and only for a closed month. */
class StatementSender
{
    public function __construct(
        private readonly AssessmentStatements $statements,
        private readonly PeriodService $periods,
        private readonly BudgetService $budgets,
    ) {
    }

    /** @return array<string,string> society id => name, for the approved budget of a year */
    public function societyOptions(Entity $circuit, int $year): array
    {
        $budget = $this->budgets->current($circuit, $year);

        return $budget
            ? $budget->assessments()->orderBy('society_name')->pluck('society_name', 'society_external_id')->all()
            : [];
    }

    /**
     * A dry run: who would get a statement, and who would not and why.
     *
     * @return array{data:array, closed:bool, ready:list<array>, no_email:list<array>, already:list<array>, nothing:list<array>}
     */
    public function plan(Entity $circuit, int $year, int $month, bool $resend = false): array
    {
        $data = $this->statements->build($circuit, $year, $month)
            ?? throw new StatementException("There is no approved budget for {$year}, so there is nothing to send.");

        $sent = StatementSend::where(['entity_id' => $circuit->id, 'year' => $year, 'month' => $month])
            ->whereIn('status', ['queued', 'sent'])
            ->pluck('society_external_id')->map(fn ($id) => (string) $id)->all();

        $plan = [
            'data' => $data,
            'closed' => $this->periods->isClosed($circuit, CarbonImmutable::create($year, $month, 1)),
            'ready' => [], 'no_email' => [], 'already' => [], 'nothing' => [],
        ];

        foreach ($data['societies'] as $s) {
            if ($s['annual'] === 0 && $s['brought_forward'] === 0 && $s['ytd_received'] === 0 && $s['balance'] === 0) {
                $plan['nothing'][] = $s;
            } elseif ($s['emails'] === []) {
                $plan['no_email'][] = $s;
            } elseif (! $resend && in_array((string) $s['external_id'], $sent, true)) {
                $plan['already'][] = $s;
            } else {
                $plan['ready'][] = $s;
            }
        }

        return $plan;
    }

    /** @return array{queued:int, no_email:list<string>, already:list<string>} */
    public function sendAll(Entity $circuit, User $user, int $year, int $month, bool $resend = false): array
    {
        $this->authorize($circuit, $user);

        $plan = $this->plan($circuit, $year, $month, $resend);
        $label = CarbonImmutable::create($year, $month, 1)->format('F Y');

        if (! $plan['closed']) {
            throw new StatementException("Please close {$label} under Month-end before sending statements.");
        }

        foreach ($plan['ready'] as $s) {
            $this->queue($circuit, $user, $year, $month, $label, $s, $s['emails'], 'queued');
        }

        activity('statements')->performedOn($circuit)->causedBy($user)
            ->withProperties(['month' => "{$year}-{$month}", 'queued' => count($plan['ready']), 'no_email' => count($plan['no_email']), 'resend' => $resend])
            ->log('assessment statements queued');

        return [
            'queued' => count($plan['ready']),
            'no_email' => array_column($plan['no_email'], 'name'),
            'already' => array_column($plan['already'], 'name'),
        ];
    }

    /** One statement, to the person sending only. Works for any month, closed or not. */
    public function sendTest(Entity $circuit, User $user, int $year, int $month, string $societyId): StatementSend
    {
        $this->authorize($circuit, $user);

        $data = $this->statements->build($circuit, $year, $month)
            ?? throw new StatementException("There is no approved budget for {$year}, so there is nothing to send.");

        $society = collect($data['societies'])->first(fn ($s) => (string) $s['external_id'] === $societyId)
            ?? throw new StatementException('That society is not in the assessment schedule.');

        $label = CarbonImmutable::create($year, $month, 1)->format('F Y');

        return $this->queue($circuit, $user, $year, $month, $label, $society, [$user->email], 'test');
    }

    private function queue(Entity $circuit, User $user, int $year, int $month, string $label, array $society, array $recipients, string $status): StatementSend
    {
        $send = StatementSend::create([
            'entity_id' => $circuit->id,
            'year' => $year,
            'month' => $month,
            'society_external_id' => (string) $society['external_id'],
            'society_name' => $society['name'],
            'recipients' => implode(', ', $recipients),
            'subject' => ($status === 'test' ? '[TEST] ' : '') . "Assessment statement – {$society['name']} – {$label}",
            'status' => $status,
            'balance_cents' => $society['balance'],
            'sent_by' => $user->id,
        ]);

        SendAssessmentStatement::dispatch($send->id);

        return $send;
    }

    private function authorize(Entity $circuit, User $user): void
    {
        if ($circuit->type !== EntityType::Circuit) {
            throw new StatementException('Assessment statements are sent by circuits.');
        }

        if (! $user->roleIn($circuit)?->can('send')) {
            throw new StatementException('Only the treasurer can send statements.');
        }
    }
}