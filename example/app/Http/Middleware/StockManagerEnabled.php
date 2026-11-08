<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StockManagerEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() || ! $request->user()->hasStockManager()) {
            abort(403, 'Access to the Industry Stock Manager has not been activated for your account.');
        }

        return $next($request);
    }
}
