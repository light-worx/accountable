<?php

namespace Tests\Feature;

use App\Enums\TransactionType;
use App\Jobs\SendAssessmentStatement;
use App\Mail\AssessmentStatementMail;
use App\Models\Budget;
use App\Models\Entity;
use App\Models\MoneyAccount;
use App\Models\Payee;
use App\Models\StatementSend;
use App\Models\User;
use App\Services\Budgets\BudgetService;
use App\Services\Budgets\StatementException;
use App\Services\Budgets\StatementSender;
use App\Services\Ledger\PeriodService;
use App\Services\Ledger\PostingException;
use App\Services\Ledger\PostingService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StatementSendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'sync']);
        Mail::fake();
    }

    /** A circuit with an approved 2026 schedule: Society X (has an email) and Society Y (none); X has paid R100 in March. */
    private function prepare(): array
    {
        $circuit = Entity::create(['type' => 'circuit', 'name' => 'Test Circuit', 'external_id' => '10']);
        $user = User::factory()->create();
        $circuit->users()->attach($user->id, ['role' => 'treasurer']);
        $account = MoneyAccount::create(['entity_id' => $circuit->id, 'name' => 'Bank', 'type' => 'bank']);

        $service = app(BudgetService::class);
        $budget = $service->create($circuit, $user, 2026, 'blank');
        $service->addSocieties($budget, $user, [
            ['id' => '100', 'slug' => 'society-x', 'name' => 'Society X', 'email' => 'x@example.org'],
            ['id' => '101', 'slug' => 'society-y', 'name' => 'Society Y', 'email' => null],
        ]);
        $service->setAssessment($budget, $user, '100', Budget::spread(120_000));
        $service->setAssessment($budget, $user, '101', Budget::spread(60_000));
        $service->approve($budget, $user, 'Circuit Quarterly Meeting');

        $payee = Payee::where(['entity_id' => $circuit->id, 'society_external_id' => '100'])->firstOrFail();
        app(PostingService::class)->post($circuit, $user, TransactionType::Income, '2026-03-10', [[
            'money_account_id' => $account->id,
            'fund_id' => $circuit->generalFund()->id,
            'category_id' => $service->assessmentCategory($circuit)->id,
            'amount_cents' => 10_000,
        ]], null, $payee->id);

        return [$circuit, $user];
    }

    public function test_statements_go_only_to_societies_with_an_email_once_the_month_is_closed(): void
    {
        [$circuit, $user] = $this->prepare();
        $sender = app(StatementSender::class);

        try {
            $sender->sendAll($circuit, $user, 2026, 3);
            $this->fail('Statements must not be sent for a month that is not closed.');
        } catch (StatementException) {
            Mail::assertNothingSent();
        }

        app(PeriodService::class)->close($circuit, $user, 2026, 3);

        $result = $sender->sendAll($circuit, $user, 2026, 3);

        $this->assertSame(1, $result['queued']);
        $this->assertSame(['Society Y'], $result['no_email']);
        Mail::assertSent(AssessmentStatementMail::class, 1);
        Mail::assertSent(AssessmentStatementMail::class, fn ($mail) => $mail->hasTo('x@example.org'));
        $this->assertSame('sent', StatementSend::first()->status);
        $this->assertNotNull(StatementSend::first()->sent_at);

        $again = $sender->sendAll($circuit, $user, 2026, 3);
        $this->assertSame(0, $again['queued']);
        $this->assertSame(['Society X'], $again['already']);
        Mail::assertSent(AssessmentStatementMail::class, 1);

        $resent = $sender->sendAll($circuit, $user, 2026, 3, resend: true);
        $this->assertSame(1, $resent['queued']);
        Mail::assertSent(AssessmentStatementMail::class, 2);
    }

    public function test_a_test_copy_goes_only_to_the_person_sending_and_needs_no_closed_month(): void
    {
        [$circuit, $user] = $this->prepare();

        $send = app(StatementSender::class)->sendTest($circuit, $user, 2026, 3, '100');

        Mail::assertSent(AssessmentStatementMail::class, fn ($mail) => $mail->hasTo($user->email)
            && str_starts_with($mail->subjectLine, '[TEST]'));
        Mail::assertNotSent(AssessmentStatementMail::class, fn ($mail) => $mail->hasTo('x@example.org'));
        $this->assertSame('test', $send->fresh()->status);
    }

    public function test_only_the_treasurer_can_send(): void
    {
        [$circuit] = $this->prepare();
        $capturer = User::factory()->create();
        $circuit->users()->attach($capturer->id, ['role' => 'capturer']);

        $this->expectException(StatementException::class);
        app(StatementSender::class)->sendTest($circuit, $capturer, 2026, 3, '100');
    }

    public function test_a_failed_send_is_recorded_with_its_reason(): void
    {
        [$circuit, $user] = $this->prepare();

        $send = StatementSend::create([
            'entity_id' => $circuit->id, 'year' => 2026, 'month' => 3,
            'society_external_id' => '100', 'society_name' => 'Society X',
            'recipients' => 'x@example.org', 'subject' => 'Test', 'status' => 'queued', 'sent_by' => $user->id,
        ]);

        (new SendAssessmentStatement($send->id))->failed(new Exception('SMTP server unreachable'));

        $this->assertSame('failed', $send->fresh()->status);
        $this->assertStringContainsString('SMTP server unreachable', $send->fresh()->error);
    }
}