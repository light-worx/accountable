<?php

namespace App\Services\Ledger;

use App\Models\Entity;
use App\Models\Transaction;

/** Re-checks the whole chain. Run nightly; any problem means something was altered outside the app. */
class LedgerVerifier
{
    /** @return list<string> problems found (empty = all good) */
    public function verify(Entity $entity): array
    {
        $problems = [];
        $expectedSeq = 1;
        $prevHash = null;

        Transaction::query()
            ->where('entity_id', $entity->id)
            ->with('lines')
            ->orderBy('seq')
            ->chunkById(200, function ($chunk) use (&$problems, &$expectedSeq, &$prevHash) {
                foreach ($chunk as $tx) {
                    if ($tx->seq !== $expectedSeq) {
                        $problems[] = "Entry numbering gap: expected #{$expectedSeq}, found #{$tx->seq} ({$tx->reference}).";
                        $expectedSeq = $tx->seq;
                    }
                    if ($tx->prev_hash !== $prevHash) {
                        $problems[] = "{$tx->reference}: link to the previous entry is broken.";
                    }

                    $recomputed = LedgerHasher::hash($tx->prev_hash, [
                        'entity_id' => $tx->entity_id,
                        'seq' => $tx->seq,
                        'type' => $tx->type->value,
                        'date' => $tx->date->format('Y-m-d'),
                        'reference' => $tx->reference,
                        'description' => $tx->description,
                        'payee_id' => $tx->payee_id,
                        'reverses_transaction_id' => $tx->reverses_transaction_id,
                        'reversal_reason' => $tx->reversal_reason,
                        'created_by' => $tx->created_by,
                        'created_at' => $tx->created_at->format('Y-m-d H:i:s'),
                    ], $tx->lines->map->only(['line_no', 'money_account_id', 'fund_id', 'category_id', 'amount_cents', 'memo'])->all());

                    if ($recomputed !== $tx->hash) {
                        $problems[] = "{$tx->reference}: contents do not match the recorded fingerprint (altered).";
                    }
                    if ($tx->type->isTransfer() && $tx->lines->sum('amount_cents') !== 0) {
                        $problems[] = "{$tx->reference}: transfer does not balance.";
                    }

                    $prevHash = $tx->hash;
                    $expectedSeq++;
                }
            });

        return $problems;
    }
}
