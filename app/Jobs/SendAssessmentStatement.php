<?php

namespace App\Jobs;

use App\Mail\AssessmentStatementMail;
use App\Models\Entity;
use App\Models\StatementSend;
use App\Models\User;
use App\Services\Budgets\SocietyContacts;
use App\Services\Ledger\PeriodService;
use App\Services\Reports\AssessmentDocuments;
use App\Services\Reports\AssessmentStatements;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Builds one society's statement PDF and emails it. Retried a few times; the result is recorded in the log. */
class SendAssessmentStatement implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $sendId)
    {
    }

    /** @return list<int> seconds to wait before each retry */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(AssessmentStatements $statements, AssessmentDocuments $documents, PeriodService $periods): void
    {
        $send = StatementSend::findOrFail($this->sendId);

        if ($send->sent_at) {
            return; // already done: a retry must never send twice
        }

        $circuit = Entity::findOrFail($send->entity_id);
        $sender = User::find($send->sent_by);

        $data = $statements->build($circuit, $send->year, $send->month)
            ?? throw new RuntimeException('The approved budget is no longer available.');

        $society = collect($data['societies'])->first(fn ($s) => (string) $s['external_id'] === (string) $send->society_external_id)
            ?? throw new RuntimeException('The society is no longer in the assessment schedule.');

        $closed = $periods->isClosed($circuit, CarbonImmutable::create($send->year, $send->month, 1));
        $senderName = $sender?->name ?? 'the circuit treasurer';
        $balance = $society['balance'];

        $mail = new AssessmentStatementMail(
            societyName: $society['name'],
            circuitName: $circuit->name,
            monthLabel: $data['month_label'],
            subjectLine: $send->subject,
            summary: [
                'headline' => $balance < 0
                    ? 'Amount owing: ' . Money::format(-$balance)
                    : ($balance > 0 ? 'Paid in advance: ' . Money::format($balance) : 'Your account is up to date.'),
                'due' => Money::format($society['month_due']),
                'received' => Money::format($society['month_received']),
                'as_at' => $data['as_at']->format('d F Y'),
            ],
            pdf: $documents->statementPdf($circuit, $data, $society, $closed, $senderName),
            filename: Str::slug($society['name']) . '-assessment-statement-' . sprintf('%04d-%02d', $send->year, $send->month) . '.pdf',
            replyTo: $sender?->email,
            senderName: $senderName,
        );

        $recipients = SocietyContacts::parse($send->recipients, strict: false);

        if ($recipients === []) {
            throw new RuntimeException('There is no valid email address to send to.');
        }

        Mail::to($recipients)->send($mail);

        $send->update(['sent_at' => now(), 'status' => $send->status === 'test' ? 'test' : 'sent', 'error' => null]);
    }

    public function failed(Throwable $e): void
    {
        StatementSend::whereKey($this->sendId)->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 500)]);
    }
}