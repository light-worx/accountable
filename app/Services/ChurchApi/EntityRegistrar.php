<?php

namespace App\Services\ChurchApi;

use App\Enums\EntityRole;
use App\Models\Entity;
use App\Models\EntityUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Sets up a society (or circuit) the first time someone chooses it.
 * Creates only the local reference records that are needed and makes the first person its Treasurer.
 * Messages are plain language and safe to show to the user.
 */
class EntityRegistrar
{
    public function __construct(private readonly Directory $directory)
    {
    }

    public function register(User $user, string $kind, string $circuitId, ?string $societyId = null, ?string $societyNumber = null): Entity
    {
        if (! in_array($kind, ['society', 'circuit'], true)) {
            throw new SyncException('Please choose what you are setting up.');
        }
        if ($kind === 'society' && blank($societyId)) {
            throw new SyncException('Please choose a society.');
        }

        // Look everything up BEFORE touching the database: a failure here changes nothing.
        $circuitData = $this->directory->circuit($circuitId);
        $societyData = $kind === 'society' ? $this->directory->society($circuitId, (string) $societyId) : null;

        return DB::transaction(function () use ($user, $circuitData, $societyData, $societyNumber) {
            $circuit = Entity::firstOrCreate(
                ['type' => 'circuit', 'external_id' => $circuitData['id']],
                ['name' => $circuitData['name'], 'number' => $circuitData['reference'], 'external_slug' => $circuitData['slug']],
            );

            $target = $circuit;

            if ($societyData) {
                $target = Entity::firstOrCreate(
                    ['type' => 'society', 'external_id' => $societyData['id']],
                    ['name' => $societyData['name'], 'external_slug' => $societyData['slug'], 'parent_id' => $circuit->id],
                );
            }

            // Lock the row so two people cannot claim the same society at the same moment.
            Entity::whereKey($target->id)->lockForUpdate()->first();

            if (EntityUser::where(['entity_id' => $target->id, 'user_id' => $user->id])->exists()) {
                return $target; // already theirs: harmless
            }

            if (EntityUser::where('entity_id', $target->id)->exists()) {
                throw new SyncException(
                    "“{$target->name}” already has someone looking after its accounts. "
                    . 'Ask them (or the system administrator) to give you access.'
                );
            }

            if ($societyData && filled($societyNumber) && blank($target->number)) {
                $target->update(['number' => trim($societyNumber)]);
            }

            EntityUser::create(['entity_id' => $target->id, 'user_id' => $user->id, 'role' => EntityRole::Treasurer]);

            return $target;
        });
    }
}