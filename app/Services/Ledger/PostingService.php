<?php

namespace App\Services\Ledger;

use App\Enums\CategoryKind;
use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\Entity;
use App\Models\Fund;
use App\Models\LedgerLine;
use App\Models\MoneyAccount;
use App\Models\Transaction;
use App\Models\TransactionAttachment;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The ONLY place that writes to the ledger. Every posted entry is:
 *  - validated in plain language,
 *  - numbered gaplessly (R-000001 ...),
 *  - hash-chained to the one before it,
 *  - written inside a lock so two people can't collide.
 * Corrections are made by reverse(), never by editing.
 *
 * Line format: money_account_id, fund_id, category_id?, amount_cents (signed: + in, - out), memo?
 */
class PostingService
{
    public function __construct(private readonly PeriodService $periods)
    {
    }

    public function post(
        Entity $entity,
        User $user,
        TransactionType $type,
        string $date,
        array $lines,
        ?string $description = null,
        ?int $payeeId = null,
        array $attachments = [],
    ): Transaction {
        $this->requireAbility($entity, $user, 'capture', 'You do not have permission to record entries.');

        $tx = $this->write(
            $entity, $user, $type,
            CarbonImmutable::parse($date)->startOfDay(),
            $this->normalise($lines),
            $this->clean($description), $payeeId, null, null,
        );

        $this->storeAttachments($tx, $user, $attachments);

        return $tx;
    }

    /** Undo an entry by posting its exact opposite. The original stays on record. */
    public function reverse(Transaction $original, User $user, string $reason): Transaction
    {
        $entity = Entity::findOrFail($original->entity_id);
        $this->requireAbility($entity, $user, 'reverse', 'Only the treasurer can reverse an entry.');

        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new PostingException('Please say why this entry is being reversed.');
        }

        $lines = $original->lines()->get()->map(fn (LedgerLine $l) => [
            'money_account_id' => $l->money_account_id,
            'fund_id' => $l->fund_id,
            'category_id' => $l->category_id,
            'amount_cents' => -$l->amount_cents,
            'memo' => $l->memo,
        ])->all();

        return $this->write(
            $entity, $user, $original->type,
            CarbonImmutable::today(),
            $this->normalise($lines),
            "Reversal of {$original->reference}: {$reason}",
            $original->payee_id, $original, $reason,
        );
    }

    private function write(
        Entity $entity,
        User $user,
        TransactionType $type,
        CarbonImmutable $date,
        array $lines,
        ?string $description,
        ?int $payeeId,
        ?Transaction $reverses,
        ?string $reason,
    ): Transaction {
        return DB::transaction(function () use ($entity, $user, $type, $date, $lines, $description, $payeeId, $reverses, $reason) {
            // One writer at a time per entity: keeps numbering gapless and the hash chain linear.
            $entity = Entity::query()->whereKey($entity->id)->lockForUpdate()->firstOrFail();

            if ($reverses) {
                if ($reverses->reverses_transaction_id) {
                    throw new PostingException('A reversal cannot itself be reversed. Enter a new entry instead.');
                }
                if (Transaction::where('reverses_transaction_id', $reverses->id)->exists()) {
                    throw new PostingException("{$reverses->reference} has already been reversed.");
                }
            }

            $this->validate($entity, $type, $date, $lines, isReversal: $reverses !== null);

            $last = Transaction::where('entity_id', $entity->id)->orderByDesc('seq')->first(['seq', 'hash']);
            $seq = ($last?->seq ?? 0) + 1;
            $createdAt = now()->format('Y-m-d H:i:s');
            $reference = $this->nextReference($entity, $type);

            $header = [
                'entity_id' => $entity->id,
                'seq' => $seq,
                'type' => $type->value,
                'date' => $date->format('Y-m-d'),
                'reference' => $reference,
                'description' => $description,
                'payee_id' => $payeeId,
                'reverses_transaction_id' => $reverses?->id,
                'reversal_reason' => $reason,
                'created_by' => $user->id,
                'created_at' => $createdAt,
            ];

            $numbered = [];
            foreach ($lines as $i => $line) {
                $numbered[] = ['line_no' => $i + 1] + $line;
            }

            $hash = LedgerHasher::hash($last?->hash, $header, $numbered);

            $tx = Transaction::create($header + ['prev_hash' => $last?->hash, 'hash' => $hash]);

            foreach ($numbered as $line) {
                LedgerLine::create($line + ['transaction_id' => $tx->id, 'entity_id' => $entity->id]);
            }

            activity('ledger')
                ->performedOn($tx)
                ->causedBy($user)
                ->withProperties(['reference' => $reference, 'type' => $type->value, 'date' => $header['date']])
                ->log($reverses ? 'reversed' : 'posted');

            return $tx;
        });
    }

    private function validate(Entity $entity, TransactionType $type, CarbonImmutable $date, array $lines, bool $isReversal): void
    {
        if ($lines === []) {
            throw new PostingException('There is nothing to record. Please enter an amount.');
        }
        if ($date->isFuture()) {
            throw new PostingException('The date cannot be in the future.');
        }
        if ($this->periods->isClosed($entity, $date)) {
            throw new PostingException('That month has been closed. Ask the treasurer to reopen it, or use a date in the current month.');
        }

        $accounts = MoneyAccount::where('entity_id', $entity->id)->whereIn('id', array_column($lines, 'money_account_id'))->get()->keyBy('id');
        $funds = Fund::where('entity_id', $entity->id)->whereIn('id', array_column($lines, 'fund_id'))->get()->keyBy('id');
        $categoryIds = array_filter(array_column($lines, 'category_id'));
        $categories = Category::where('entity_id', $entity->id)->whereIn('id', $categoryIds)->get()->keyBy('id');

        foreach ($lines as $line) {
            if ($line['amount_cents'] === 0) {
                throw new PostingException('An amount cannot be zero.');
            }
            $account = $accounts[$line['money_account_id']] ?? throw new PostingException('Please choose a valid account.');
            $fund = $funds[$line['fund_id']] ?? throw new PostingException('Please choose a valid fund.');
            if (! $isReversal && (! $account->is_active || ! $fund->is_active)) {
                throw new PostingException('“' . (! $account->is_active ? $account->name : $fund->name) . '” is switched off. Please choose another.');
            }
            if ($line['category_id'] && ! isset($categories[$line['category_id']])) {
                throw new PostingException('Please choose a valid category.');
            }
        }

        if ($isReversal) {
            return;
        }

        switch ($type) {
            case TransactionType::Income:
            case TransactionType::Expense:
                $income = $type === TransactionType::Income;
                foreach ($lines as $line) {
                    $category = $categories[$line['category_id'] ?? 0] ?? throw new PostingException('Please choose a category for each amount.');
                    $okKind = $income ? CategoryKind::Income : CategoryKind::Expense;
                    if ($category->kind !== $okKind || ! $category->is_active) {
                        throw new PostingException("“{$category->name}” cannot be used here.");
                    }
                    if (($income && $line['amount_cents'] < 0) || (! $income && $line['amount_cents'] > 0)) {
                        throw new PostingException('Amounts must be positive.');
                    }
                }
                break;

            case TransactionType::AccountTransfer:
            case TransactionType::FundTransfer:
                $this->validateTransfer($type, $lines);
                break;
        }

        $this->assertNoOverdraft($entity, $lines, $accounts, $funds);
    }

    private function validateTransfer(TransactionType $type, array $lines): void
    {
        if (count($lines) !== 2 || array_sum(array_column($lines, 'amount_cents')) !== 0) {
            throw new PostingException('A transfer must take the same amount out of one place and put it into another.');
        }
        if (array_filter($lines, fn ($l) => $l['category_id'])) {
            throw new PostingException('Transfers do not use a category.');
        }

        $field = $type === TransactionType::AccountTransfer ? 'money_account_id' : 'fund_id';
        $other = $type === TransactionType::AccountTransfer ? 'fund_id' : 'money_account_id';

        if ($lines[0][$field] === $lines[1][$field]) {
            throw new PostingException('Please choose two different ' . ($type === TransactionType::AccountTransfer ? 'accounts' : 'funds') . '.');
        }
        if ($lines[0][$other] !== $lines[1][$other]) {
            throw new PostingException('A transfer can only change one thing at a time.');
        }
    }

    private function assertNoOverdraft(Entity $entity, array $lines, $accounts, $funds): void
    {
        if ($entity->allow_overdrawn) {
            return;
        }

        $byAccount = [];
        $byFund = [];
        foreach ($lines as $l) {
            $byAccount[$l['money_account_id']] = ($byAccount[$l['money_account_id']] ?? 0) + $l['amount_cents'];
            $byFund[$l['fund_id']] = ($byFund[$l['fund_id']] ?? 0) + $l['amount_cents'];
        }

        foreach ($byAccount as $id => $delta) {
            if ($delta < 0 && ($balance = $accounts[$id]->balanceCents()) + $delta < 0) {
                throw new PostingException(sprintf(
                    'There is not enough money in “%s”. It has %s and this takes out %s.',
                    $accounts[$id]->name, Money::format($balance), Money::format(-$delta)
                ));
            }
        }
        foreach ($byFund as $id => $delta) {
            if ($delta < 0 && ($balance = $funds[$id]->balanceCents()) + $delta < 0) {
                throw new PostingException(sprintf(
                    'There is not enough in the “%s”. It has %s and this takes out %s.',
                    $funds[$id]->name, Money::format($balance), Money::format(-$delta)
                ));
            }
        }
    }

    private function nextReference(Entity $entity, TransactionType $type): string
    {
        $key = $type->referencePrefix();

        $row = DB::table('number_sequences')->where('entity_id', $entity->id)->where('key', $key)->lockForUpdate()->first();
        $next = ($row->last_number ?? 0) + 1;

        DB::table('number_sequences')->updateOrInsert(
            ['entity_id' => $entity->id, 'key' => $key],
            ['last_number' => $next],
        );

        return sprintf('%s-%06d', $key, $next);
    }

    private function normalise(array $lines): array
    {
        return array_values(array_map(fn (array $l) => [
            'money_account_id' => (int) $l['money_account_id'],
            'fund_id' => (int) $l['fund_id'],
            'category_id' => filled($l['category_id'] ?? null) ? (int) $l['category_id'] : null,
            'amount_cents' => (int) $l['amount_cents'],
            'memo' => $this->clean($l['memo'] ?? null),
        ], $lines));
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function requireAbility(Entity $entity, User $user, string $ability, string $message): void
    {
        if (! $user->roleIn($entity)?->can($ability)) {
            throw new PostingException($message);
        }
    }

    private function storeAttachments(Transaction $tx, User $user, array $paths): void
    {
        $disk = Storage::disk('local');

        foreach ($paths as $path) {
            if (! $disk->exists($path)) {
                continue;
            }
            TransactionAttachment::create([
                'transaction_id' => $tx->id,
                'path' => $path,
                'original_name' => basename($path),
                'sha256' => hash_file('sha256', $disk->path($path)),
                'uploaded_by' => $user->id,
                'created_at' => now()->format('Y-m-d H:i:s'),
            ]);
        }
    }
}
