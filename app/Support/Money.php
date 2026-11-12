<?php

namespace App\Support;

/** All amounts are stored as integer cents. Never floats. */
final class Money
{
    public static function toCents(string|int|float|null $rands): int
    {
        return (int) round(((float) ($rands ?? 0)) * 100);
    }

    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';

        return $sign . 'R' . number_format(abs($cents) / 100, 2, '.', ' ');
    }
}
