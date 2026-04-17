<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $lang = $request->query('lang');

        if (!$lang || !in_array($lang, ['en', 'pl'])) {
            return $next($request);
        }

        App::setLocale($lang);

        return $next($request);
    }
}
