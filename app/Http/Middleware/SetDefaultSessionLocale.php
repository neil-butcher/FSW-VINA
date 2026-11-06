<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetDefaultSessionLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = config('app.default_session_locale');

        if ($locale && ! $request->session()->has('locale')) {
            $request->session()->put('locale', $locale);
        }

        return $next($request);
    }
}
