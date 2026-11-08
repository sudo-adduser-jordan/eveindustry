<?php

namespace App\Http\Controllers;

use App\Services\AssetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The asset pages live in the React app (/app/assets), which reads
 * /api/v1/assets. Only the refresh button of the remaining Blade pages posts
 * here.
 */
class AssetController extends Controller
{
    public function __construct(protected AssetService $assetService) {}

    public function refresh(Request $request): RedirectResponse
    {
        // The user's asset list stays visible until the queued job replaces it.
        $this->assetService->queueRefresh($request->user()->id);

        return redirect()->back(302, [], route('assets.index'))->with('success', 'Asset refresh queued. Your data will be updated shortly.');
    }
}
