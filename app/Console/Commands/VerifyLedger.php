<?php

namespace App\Console\Commands;

use App\Models\Entity;
use App\Services\Ledger\LedgerVerifier;
use Illuminate\Console\Command;

class VerifyLedger extends Command
{
    protected $signature = 'ledger:verify {entity? : Entity id (default: all)}';

    protected $description = 'Verify the tamper-evident hash chain of every entity ledger';

    public function handle(LedgerVerifier $verifier): int
    {
        $entities = $this->argument('entity')
            ? Entity::whereKey($this->argument('entity'))->get()
            : Entity::all();

        $failed = false;
        foreach ($entities as $entity) {
            $problems = $verifier->verify($entity);
            if ($problems) {
                $failed = true;
                $this->error("{$entity->name} (#{$entity->id}): " . count($problems) . ' problem(s)');
                foreach ($problems as $p) {
                    $this->line("  - {$p}");
                }
            } else {
                $this->info("{$entity->name}: OK");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
