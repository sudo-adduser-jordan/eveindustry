<?php

namespace App\Http\Controllers\Api\V1\Programs;

use App\Http\Controllers\Controller;
use App\Services\Programs\IndustryRefreshService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Cache refresh triggers for the industry pages. They recompute inline and
 * dispatch no job, so they answer 204 once done; read the fresh data from
 * the matching GET endpoint afterwards.
 */
class IndustryRefreshController extends Controller
{
    public function __construct(private IndustryRefreshService $refresher) {}

    /**
     * Clear all industry caches.
     *
     * Forgets the industry and ESI caches of all your characters. Allowed once
     * every 30 minutes; sooner it answers 429 and clears nothing.
     */
    public function clear(Request $request): Response
    {
        $throttled = $this->refresher->clearAllCaches($request->user()->id);

        if ($throttled !== null) {
            abort(429, $throttled);
        }

        return response()->noContent();
    }

    /**
     * Refresh the industry availability.
     *
     * Forgets the cached slot data of your characters and recomputes it now.
     */
    public function refreshAvailability(Request $request): Response
    {
        $this->refresher->refreshAvailability($request->user()->id);

        return response()->noContent();
    }

    /**
     * Refresh the can-produce data.
     *
     * Forgets the cached blueprints, assets and roles of your characters and
     * corporations and recomputes what you can produce now.
     */
    public function refreshCanProduce(Request $request): Response
    {
        $this->refresher->refreshCanProduce($request->user());

        return response()->noContent();
    }
}
