<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The shared demo account is read-mostly. Every write is denied unless its path
 * is on this list: calculators, personal planning data and refresh buttons
 * (which do nothing for demo users). Anything that touches accounts, push
 * devices, corporations, the wormhole map or moon scans stays blocked.
 */
class BlockDemoUser
{
    private const ALLOWED_WRITES = [
        'logout',
        'api/v1/logout',
        'api/v1/calculators/*',
        'api/v1/appraisals',
        'api/v1/build-programs',
        'api/v1/build-programs/*',
        'api/v1/stock/items',
        'api/v1/stock/items/*',
        'api/v1/station-configs',
        'api/v1/station-configs/*',
        'api/v1/structures',
        'api/v1/structures/*',
        'api/v1/assets/refresh',
        'api/v1/industry/availability/*',
        'api/v1/industry/can-produce/refresh',
        'api/v1/industry/stock/refresh',
        'api/v1/planetary/refresh',
        'api/v1/corporation/projects/refresh',
        'api/v1/corporation/donations/refresh',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isDemo() || $request->isMethodSafe() || $request->is(self::ALLOWED_WRITES)) {
            return $next($request);
        }

        $message = 'This is disabled on the demo account.';
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => $message, 'code' => 'demo_restricted'], 403);
        }
        abort(403, $message);
    }
}
