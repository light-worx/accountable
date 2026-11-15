<?php

namespace App\Console\Commands;

use App\Enums\EntityType;
use App\Models\Category;
use App\Models\Entity;
use App\Support\DefaultSetup;
use Illuminate\Console\Command;

class MapReturnLines extends Command
{
    protected $signature = 'categories:map-returns';

    protected $description = 'Fill in blank 4S / 4C line codes on existing societies and circuits and add any missing standard types';

    public function handle(): int
    {
        $updated = 0;

        foreach ([EntityType::Society, EntityType::Circuit] as $type) {
            $codes = DefaultSetup::standardCodes($type);

            foreach (Entity::where('type', $type->value)->get() as $entity) {
                DefaultSetup::apply($entity);

                foreach ($codes as $key => $code) {
                    [$kind, $name] = explode('|', $key, 2);

                    $updated += Category::where(['entity_id' => $entity->id, 'kind' => $kind, 'name' => $name])
                        ->where(fn ($q) => $q->whereNull('return_line_code')->orWhere('return_line_code', ''))
                        ->update(['return_line_code' => $code]);
                }
            }
        }

        activity('default')->log("Mapped {$updated} income and expenditure types to annual return lines");
        $this->info("Mapped {$updated} type(s) to return lines.");

        return self::SUCCESS;
    }
}