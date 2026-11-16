<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Entity;
use App\Support\DefaultSetup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TidyStandardTypes extends Command
{
    protected $signature = 'categories:tidy {--entity= : Only this entity id} {--dry-run : Show what would be switched off, without changing anything}';

    protected $description = 'Switch off standard income and payment types that have never been used, keeping the common ones';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $grand = 0;

        $entities = Entity::whereIn('type', ['society', 'circuit'])
            ->when($this->option('entity'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('name')
            ->get();

        foreach ($entities as $entity) {
            $standard = DefaultSetup::standardCodes($entity->type);
            $common = DefaultSetup::commonNames($entity->type);

            $unused = Category::where('entity_id', $entity->id)->where('is_active', true)->get()
                ->filter(function (Category $c) use ($standard, $common) {
                    $key = $c->kind->value . '|' . $c->name;

                    return isset($standard[$key])                         // a standard type, not one the treasurer made
                        && ! in_array($key, $common, true)                // and not one of the common ones
                        && ! $c->lines()->exists()                        // with no entries in the cash book
                        && ! $this->budgeted($c->id);                     // and no budget amounts
                });

            if ($unused->isEmpty()) {
                continue;
            }

            $this->line("{$entity->name}: " . ($dry ? 'would switch off ' : 'switched off ') . $unused->count() . ' unused type(s)');

            if ($dry) {
                $unused->each(fn (Category $c) => $this->line("   - {$c->name}"));
            } else {
                Category::whereIn('id', $unused->pluck('id'))->update(['is_active' => false]);
                activity('default')->performedOn($entity)
                    ->withProperties(['switched_off' => $unused->count()])->log('unused standard types switched off');
            }

            $grand += $unused->count();
        }

        $this->info(($dry ? 'Would switch off ' : 'Switched off ') . "{$grand} type(s) in total.");

        return self::SUCCESS;
    }

    private function budgeted(int $categoryId): bool
    {
        $sum = implode('+', array_map(fn ($m) => "m{$m}", range(1, 12)));

        return DB::table('budget_lines')->where('category_id', $categoryId)->whereRaw("({$sum}) <> 0")->exists();
    }
}