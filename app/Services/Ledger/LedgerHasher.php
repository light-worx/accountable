<?php

namespace App\Services\Ledger;

/**
 * Hash chain: each posted transaction's hash covers its own content plus the previous
 * transaction's hash. Change any past figure and every later hash stops matching.
 */
final class LedgerHasher
{
    public static function hash(?string $prevHash, array $header, array $lines): string
    {
        $payload = [
            'entity_id' => (int) $header['entity_id'],
            'seq' => (int) $header['seq'],
            'type' => (string) $header['type'],
            'date' => (string) $header['date'],
            'reference' => (string) $header['reference'],
            'description' => isset($header['description']) ? (string) $header['description'] : null,
            'payee_id' => isset($header['payee_id']) ? (int) $header['payee_id'] : null,
            'reverses_transaction_id' => isset($header['reverses_transaction_id']) ? (int) $header['reverses_transaction_id'] : null,
            'reversal_reason' => isset($header['reversal_reason']) ? (string) $header['reversal_reason'] : null,
            'created_by' => (int) $header['created_by'],
            'created_at' => (string) $header['created_at'],
            'lines' => array_map(fn (array $l) => [
                'line_no' => (int) $l['line_no'],
                'money_account_id' => (int) $l['money_account_id'],
                'fund_id' => (int) $l['fund_id'],
                'category_id' => isset($l['category_id']) ? (int) $l['category_id'] : null,
                'amount_cents' => (int) $l['amount_cents'],
                'memo' => isset($l['memo']) ? (string) $l['memo'] : null,
            ], array_values($lines)),
        ];

        return hash(
            'sha256',
            ($prevHash ?? str_repeat('0', 64)) . '|' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }
}
