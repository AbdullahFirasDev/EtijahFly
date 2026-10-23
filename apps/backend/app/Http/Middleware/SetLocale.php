<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Arabic by default; English when requested. Extend SUPPORTED to add languages. */
class SetLocale
{
    public const SUPPORTED = ['ar', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->getPreferredLanguage(self::SUPPORTED) ?? config('app.locale');
        if ($request->hasHeader('X-Locale') && in_array($request->header('X-Locale'), self::SUPPORTED, true)) {
            $locale = $request->header('X-Locale');
        }

        app()->setLocale($locale);

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);

        return $response;
    }
}
