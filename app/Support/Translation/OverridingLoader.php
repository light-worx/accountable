<?php

namespace App\Support\Translation;

use Illuminate\Contracts\Translation\Loader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Wraps Laravel's file loader and lays the on-screen corrections over the translation files. */
class OverridingLoader implements Loader
{
    public function __construct(private readonly Loader $inner)
    {
    }

    public function load($locale, $group, $namespace = null)
    {
        $lines = $this->inner->load($locale, $group, $namespace);

        if ($group === '*' && $namespace === '*') {
            $lines = array_merge($lines, $this->overrides((string) $locale));
        }

        return $lines;
    }

    public function addNamespace($namespace, $hint)
    {
        $this->inner->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path)
    {
        $this->inner->addJsonPath($path);
    }

    public function namespaces()
    {
        return $this->inner->namespaces();
    }

    public function __call(string $method, array $arguments)
    {
        return $this->inner->{$method}(...$arguments);
    }

    /** @return array<string,string> */
    private function overrides(string $locale): array
    {
        try {
            return Cache::rememberForever(
                "translation_overrides.{$locale}",
                fn () => DB::table('translation_overrides')->where('locale', $locale)->pluck('translation', 'source')->all(),
            );
        } catch (Throwable) {
            return []; // e.g. before the migration has run
        }
    }
}