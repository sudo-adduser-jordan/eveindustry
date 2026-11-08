<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user() || !$request->user()->isAdmin()) {
            // The JSON API answers 403 so the React app can show its own page.
            abort_if($request->is('api/*'), 403, 'Admin access required.');

            return redirect()->route('dashboard')->with('error', 'Access denied.');
        }

        return $next($request);
    }
}
