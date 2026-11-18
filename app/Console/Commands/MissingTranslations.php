<?php

namespace App\Console\Commands;

use App\Support\Translation\Strings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class MissingTranslations extends Command
{
    protected $signature = 'translations:missing {locale? : e.g. zu} {--list : Show each missing text}';

    protected $description = 'Report which texts in the code have no translation yet, per language';

    public function handle(): int
    {
        $inCode = Strings::inCode();
        $default = array_key_first(config('languages.locales'));
        $locales = $this->argument('locale')
            ? [$this->argument('locale')]
            : array_values(array_diff(array_keys(config('languages.locales')), [$default]));

        $rows = [];

        foreach ($locales as $locale) {
            $have = array_merge(Strings::inFile($locale), $this->overrides($locale));
            $missing = array_values(array_diff(array_keys($inCode), array_keys($have)));
            $unused = array_values(array_diff(array_keys(Strings::inFile($locale)), array_keys($inCode)));

            $rows[] = [$locale, count($inCode), count($inCode) - count($missing), count($missing), count($unused)];

            if ($this->option('list')) {
                $this->line("Missing in {$locale}:");
                foreach ($missing as $text) {
                    $this->line("  - {$text}   [" . $inCode[$text][0] . ']');
                }
                if ($unused) {
                    $this->line("In {$locale}.json but not found in the code (changed or removed wording?):");
                    foreach ($unused as $text) {
                        $this->line("  - {$text}");
                    }
                }
            }
        }

        $this->table(['Language', 'Texts in code', 'Translated', 'Missing', 'In file but unused'], $rows);

        return self::SUCCESS;
    }

    private function overrides(string $locale): array
    {
        try {
            return DB::table('translation_overrides')->where('locale', $locale)->pluck('translation', 'source')->all();
        } catch (Throwable) {
            return [];
        }
    }
}