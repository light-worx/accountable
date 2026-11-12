<?php

namespace App\Services\Ledger;

use App\Models\Entity;
use App\Models\PeriodEvent;
use App\Models\User;
use Carbon\CarbonInterface;

/** Month-end locking. Nothing can be posted into a closed month until a treasurer reopens it (with a reason, on record). */
class PeriodService
{
    public function isClosed(Entity $entity, CarbonInterface $date): bool
    {
        $last = PeriodEvent::query()
            ->where('entity_id', $entity->id)
            ->where('year', $date->year)
            ->where('month', $date->month)
            ->orderByDesc('id')
            ->first();

        return $last?->action === 'close';
    }

    public function close(Entity $entity, User $user, int $year, int $month): PeriodEvent
    {
        $this->authorize($entity, $user);

        return $this->record($entity, $user, $year, $month, 'close', null);
    }

    public function reopen(Entity $entity, User $user, int $year, int $month, string $reason): PeriodEvent
    {
        $this->authorize($entity, $user);

        if (mb_strlen(trim($reason)) < 5) {
            throw new PostingException('Please give a reason for reopening this month.');
        }

        return $this->record($entity, $user, $year, $month, 'reopen', trim($reason));
    }

    private function record(Entity $entity, User $user, int $year, int $month, string $action, ?string $reason): PeriodEvent
    {
        return PeriodEvent::create([
            'entity_id' => $entity->id,
            'year' => $year,
            'month' => $month,
            'action' => $action,
            'reason' => $reason,
            'user_id' => $user->id,
            'created_at' => now()->format('Y-m-d H:i:s'),
        ]);
    }

    private function authorize(Entity $entity, User $user): void
    {
        if (! $user->roleIn($entity)?->can('close')) {
            throw new PostingException('Only the treasurer can close or reopen a month.');
        }
    }
}
