<?php

use App\Http\Controllers\Api\V1\Heavy\BuildProgramProgressController;
use App\Http\Controllers\Api\V1\Heavy\CorporationBuildProgramProgressController;
use App\Http\Controllers\Api\V1\Heavy\IndustryController;
use App\Http\Controllers\Api\V1\Heavy\MoonScanController;
use App\Http\Controllers\Api\V1\Heavy\StructureController;
use App\Http\Controllers\Api\V1\Heavy\WholemapController;
use Illuminate\Support\Facades\Route;

/*
| /api/v1 routes for the "heavy" part (see docs/api-v1-migration.md, B5 plan).
| Required from routes/api.php inside the auth:sanctum group, with the
| api.v1. name prefix already applied.
|
| Permissions are model policies registered in HeavyPolicyServiceProvider and
| attached with the `can:` middleware (or Gate::authorize where the model is
| looked up in the controller).
*/

Route::name('heavy.')->group(function () {
    Route::get('/build-programs/{program}/progress', [BuildProgramProgressController::class, 'progress'])
        ->middleware('can:view,program')
        ->name('build-programs.progress');
    Route::get('/build-programs/{program}/asset-locations', [BuildProgramProgressController::class, 'assetLocations'])
        ->middleware('can:view,program')
        ->name('build-programs.asset-locations');

    // The controller re-resolves the config the policy checked, because it
    // needs the director character from it.
    Route::get('/corporation/build-programs/{program}/progress', [CorporationBuildProgramProgressController::class, 'progress'])
        ->middleware('can:view,program')
        ->name('corporation.build-programs.progress');
    Route::get('/corporation/build-programs/{program}/asset-locations', [CorporationBuildProgramProgressController::class, 'assetLocations'])
        ->middleware('can:view,program')
        ->name('corporation.build-programs.asset-locations');

    Route::get('/industry/availability', [IndustryController::class, 'availability'])->name('industry.availability');
    Route::get('/industry/reprocessing', [IndustryController::class, 'reprocessing'])->name('industry.reprocessing');

    Route::get('/structures', [StructureController::class, 'index'])->name('structures.index');

    Route::post('/moon-scans', [MoonScanController::class, 'store'])->name('moon-scans.store');
    Route::get('/moon-scans/systems', [MoonScanController::class, 'systems'])->name('moon-scans.systems');
    Route::get('/moon-scans/systems/{system}', [MoonScanController::class, 'system'])->name('moon-scans.system');

    Route::get('/wholemap/connections/{connection}/jump-logs', [WholemapController::class, 'jumpLogs'])
        ->middleware('can:view,connection')
        ->name('wholemap.jump-logs');
    Route::get('/wholemap/location', [WholemapController::class, 'location'])->name('wholemap.location');
    Route::get('/wholemap', [WholemapController::class, 'map'])->name('wholemap.map');
});
