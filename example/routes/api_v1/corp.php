<?php

use App\Http\Controllers\Api\V1\Corp\AssetController;
use App\Http\Controllers\Api\V1\Corp\CorporationBuildProgramPageController;
use App\Http\Controllers\Api\V1\Corp\DonationConfigController;
use App\Http\Controllers\Api\V1\Corp\DonationController;
use App\Http\Controllers\Api\V1\Corp\EssController;
use App\Http\Controllers\Api\V1\Corp\ProjectController;
use App\Http\Controllers\Api\V1\Corp\StockSearchController;
use App\Http\Controllers\Api\V1\Corp\StructureWindowController;
use App\Policies\CorporationPolicy;
use Illuminate\Support\Facades\Route;

/*
| /api/v1 routes for the "corp" group (see docs/api-v1-migration.md).
| Required from routes/api.php inside the auth:sanctum group, with the
| api.v1. name prefix already applied.
|
| Permission checks are gates from CorporationPolicy (via `can:`) and the
| existing `stock-manager` middleware; both fail with 403 code "forbidden".
*/

Route::name('corp.')->group(function () {
    Route::get('/assets', [AssetController::class, 'index'])->name('assets');
    Route::get('/assets/by-location', [AssetController::class, 'byLocation'])->name('assets.by-location');
    Route::get('/assets/materials', [AssetController::class, 'materials'])->name('assets.materials');
    Route::get('/assets/ships', [AssetController::class, 'ships'])->name('assets.ships');
    Route::get('/assets/abyssal', [AssetController::class, 'abyssal'])->name('assets.abyssal');
    Route::post('/assets/refresh', [AssetController::class, 'refresh'])
        ->middleware('throttle:6,1')
        ->name('assets.refresh');

    Route::get('/corporation/assets', [AssetController::class, 'corporation'])
        ->middleware('can:'.CorporationPolicy::VIEW_ASSETS)
        ->name('corporation.assets');
    Route::get('/corporation/assets/by-location', [AssetController::class, 'corporationByLocation'])
        ->middleware('can:'.CorporationPolicy::VIEW_ASSETS)
        ->name('corporation.assets.by-location');

    Route::get('/corporation/donations', [DonationController::class, 'index'])
        ->middleware('can:'.CorporationPolicy::VIEW_DONATIONS)
        ->name('corporation.donations');

    Route::get('/corporation/ess', [EssController::class, 'index'])
        ->middleware('can:'.CorporationPolicy::VIEW_ESS)
        ->name('corporation.ess');

    Route::get('/corporation/projects', [ProjectController::class, 'index'])
        ->middleware('can:'.CorporationPolicy::VIEW_PROJECTS)
        ->name('corporation.projects');

    // Donation tracking: status is open so the page can offer to enable it.
    Route::get('/corporation/donations/status', [DonationConfigController::class, 'status'])->name('corporation.donations.status');
    Route::post('/corporation/donations/enable', [DonationConfigController::class, 'enable'])->name('corporation.donations.enable');
    Route::post('/corporation/donations/disable', [DonationConfigController::class, 'disable'])->name('corporation.donations.disable');
    Route::post('/corporation/donations/refresh', [DonationConfigController::class, 'refresh'])
        ->middleware(['throttle:6,1', 'can:'.CorporationPolicy::VIEW_DONATIONS])
        ->name('corporation.donations.refresh');

    Route::get('/corporation/projects/contributors', [ProjectController::class, 'contributors'])
        ->middleware('can:'.CorporationPolicy::VIEW_PROJECTS)
        ->name('corporation.projects.contributors');
    Route::post('/corporation/projects/refresh', [ProjectController::class, 'refresh'])
        ->middleware(['throttle:6,1', 'can:'.CorporationPolicy::VIEW_PROJECTS])
        ->name('corporation.projects.refresh');

    Route::middleware('can:'.CorporationPolicy::VIEW_ESS)->group(function () {
        Route::post('/corporation/ess', [EssController::class, 'store'])->name('corporation.ess.store');
        Route::put('/corporation/ess/{system}', [EssController::class, 'update'])->name('corporation.ess.update');
        Route::delete('/corporation/ess/{system}', [EssController::class, 'destroy'])->name('corporation.ess.destroy');
        Route::post('/corporation/ess/{system}/payouts', [EssController::class, 'logPayout'])->name('corporation.ess.payouts');
    });

    Route::middleware('can:'.CorporationPolicy::VIEW_STRUCTURE_TIMERS)->group(function () {
        Route::get('/corporation/structure-vulnerability', [StructureWindowController::class, 'index'])->name('corporation.structure-vulnerability');
        Route::post('/corporation/structure-vulnerability', [StructureWindowController::class, 'store'])->name('corporation.structure-vulnerability.store');
        Route::put('/corporation/structure-vulnerability/{window}', [StructureWindowController::class, 'update'])->name('corporation.structure-vulnerability.update');
        Route::delete('/corporation/structure-vulnerability/{window}', [StructureWindowController::class, 'destroy'])->name('corporation.structure-vulnerability.destroy');
    });

    // Corporate build programs page. Writes on programs are in writes_programs.php.
    Route::get('/corporation/build-programs', [CorporationBuildProgramPageController::class, 'index'])->name('corporation.build-programs.index');
    Route::post('/corporation/build-programs/enable', [CorporationBuildProgramPageController::class, 'enable'])->name('corporation.build-programs.enable');
    Route::post('/corporation/build-programs/disable', [CorporationBuildProgramPageController::class, 'disable'])->name('corporation.build-programs.disable');
    Route::get('/corporation/build-programs/{program}', [CorporationBuildProgramPageController::class, 'show'])
        ->middleware('can:view,program')
        ->name('corporation.build-programs.show');

    Route::get('/stock/search', [StockSearchController::class, 'index'])
        ->middleware('stock-manager')
        ->name('stock.search');
});
