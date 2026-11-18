<?php

namespace App\Support\Translation;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class Strings
{
    /** @return array<string,list<string>> every English text passed to __() in the code, with the files it appears in */
    public static function inCode(): array
    {
        $found = [];

        foreach ([app_path(), resource_path('views')] as $root) {
            if (! is_dir($root)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                if (preg_match_all('/(?:__|trans)\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/s', $source, $matches)) {
                    foreach ($matches[1] as $raw) {
                        $key = str_replace(["\\'", '\\\\'], ["'", '\\'], $raw);
                        $found[$key][] = str_replace(base_path() . '/', '', $file->getPathname());
                    }
                }
            }
        }

        ksort($found);

        return $found;
    }

    /** @return array<string,string> English text => translation, from the file for one language */
    public static function inFile(string $locale): array
    {
        $path = lang_path("{$locale}.json");

        return is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
    }

    /** @return array<string,string> English text => label, for dropdowns */
    public static function options(): array
    {
        $keys = array_keys(self::inCode());

        foreach (array_keys(config('languages.locales')) as $locale) {
            $keys = array_merge($keys, array_keys(self::inFile($locale)));
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        return array_combine($keys, array_map(fn ($k) => mb_strimwidth($k, 0, 100, '…'), $keys));
    }

    /** @return list<string> the :placeholders in a text, sorted */
    public static function placeholders(string $text): array
    {
        preg_match_all('/(?<![A-Za-z0-9]):[A-Za-z_]+/', $text, $matches);

        $found = array_values(array_unique($matches[0]));
        sort($found);

        return $found;
    }
}