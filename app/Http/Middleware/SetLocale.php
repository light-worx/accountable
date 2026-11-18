<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** The person's own choice, else what their browser asks for, else English. */
class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $supported = array_keys(config('languages.locales'));
        $locale = $request->user()?->locale;

        if (! in_array($locale, $supported, true)) {
            $locale = $request->getPreferredLanguage($supported) ?? $supported[0];
        }

        app()->setLocale($locale);

        return $next($request);
    }
}