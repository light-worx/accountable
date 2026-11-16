<?php

namespace App\Services\Budgets;

use App\Enums\EntityType;
use App\Enums\PayeeType;
use App\Models\Entity;
use App\Models\Payee;
use App\Services\ChurchApi\Directory;

/** Makes each society in a circuit available as a "person" to record assessment payments against. */
class SocietyPayees
{
    public function __construct(private readonly Directory $directory)
    {
    }

    /** Looks up the circuit's societies on church.net.za and adds any that are missing. Returns how many were added. */
    public function loadFromDirectory(Entity $circuit): int
    {
        if (blank($circuit->external_id)) {
            throw new BudgetException('This circuit was not set up from church.net.za, so its societies cannot be looked up.');
        }

        return $this->ensure($circuit, array_values($this->directory->societies((string) $circuit->external_id)));
    }

    /**
     * Adds any society that is not already in the list. Nothing existing is changed.
     *
     * @param  list<array{id:string,name:string}>  $societies
     */
    public function ensure(Entity $circuit, array $societies): int
    {
        if ($circuit->type !== EntityType::Circuit) {
            return 0;
        }

        $created = 0;

        foreach ($societies as $society) {
            $id = (string) $society['id'];

            if (Payee::where(['entity_id' => $circuit->id, 'society_external_id' => $id])->exists()) {
                continue;
            }

            $name = $society['name'];

            if (Payee::where(['entity_id' => $circuit->id, 'name' => $name])->exists()) {
                $name = "{$name} (society {$id})";
            }

            Payee::create([
                'entity_id' => $circuit->id,
                'name' => $name,
                'type' => PayeeType::Society,
                'society_external_id' => $id,
                'is_active' => true,
            ]);

            $created++;
        }

        return $created;
    }
}