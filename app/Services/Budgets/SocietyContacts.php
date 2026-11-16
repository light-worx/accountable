<?php

namespace App\Services\Budgets;

use App\Enums\EntityType;
use App\Models\Entity;
use App\Models\SocietyContact;
use App\Models\User;

/** Where a society's statements are sent: the circuit's own entry if there is one, otherwise church.net.za's. */
class SocietyContacts
{
    /**
     * Splits text into addresses (commas, semicolons, spaces or new lines), lower-cased and de-duplicated.
     * Strict mode refuses anything invalid; lenient mode (for data from elsewhere) quietly skips it.
     *
     * @return list<string>
     */
    public static function parse(?string $text, bool $strict = true): array
    {
        $parts = preg_split('/[\s,;]+/', trim((string) $text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $emails = [];

        foreach ($parts as $part) {
            $part = mb_strtolower($part);

            if (! filter_var($part, FILTER_VALIDATE_EMAIL)) {
                if ($strict) {
                    throw new BudgetException("“{$part}” is not a valid email address.");
                }

                continue;
            }

            $emails[$part] = $part;
        }

        return array_values($emails);
    }

    /** @return array<string,string> society external id => the circuit's own email entry */
    public function overrides(Entity $circuit): array
    {
        return SocietyContact::where('entity_id', $circuit->id)->pluck('email', 'society_external_id')->all();
    }

    /**
     * The addresses statements go to for a society.
     *
     * @return list<string>
     */
    public function emailsFor(Entity $circuit, string $societyExternalId, ?string $directoryEmail): array
    {
        $override = SocietyContact::where(['entity_id' => $circuit->id, 'society_external_id' => $societyExternalId])->value('email');

        return self::parse(filled($override) ? $override : $directoryEmail, strict: false);
    }

    /** Sets the circuit's own address(es) for a society. Blank clears it, going back to church.net.za's. */
    public function set(Entity $circuit, User $user, string $societyExternalId, string $societyName, ?string $email): void
    {
        if ($circuit->type !== EntityType::Circuit) {
            throw new BudgetException('Society contact details are kept by the circuit.');
        }

        if (! $user->roleIn($circuit)?->can('setup')) {
            throw new BudgetException('Only the treasurer can change contact details.');
        }

        $emails = self::parse($email);
        $where = ['entity_id' => $circuit->id, 'society_external_id' => $societyExternalId];

        if ($emails === []) {
            SocietyContact::where($where)->get()->each->delete();

            return;
        }

        SocietyContact::updateOrCreate($where, ['society_name' => $societyName, 'email' => implode(', ', $emails)]);
    }
}