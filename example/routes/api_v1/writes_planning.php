<?php

use App\Http\Controllers\Api\V1\Planning\InventionPlanController;
use App\Http\Controllers\Api\V1\Planning\MoonOccupationController;
use App\Http\Controllers\Api\V1\Planning\ReactionPlanController;
use App\Http\Controllers\Api\V1\Planning\StationConfigController;
use App\Http\Controllers\Api\V1\Planning\WholemapConnectionController;
use App\Http\Controllers\Api\V1\Planning\WholemapNoteController;
use App\Http\Controllers\Api\V1\Planning\WholemapSignatureController;
use Illuminate\Support\Facades\Route;

/*
| /api/v1 routes for the "writes_planning" part (see docs/api-v1-migration.md, B5 plan).
| Required from routes/api.php inside the auth:sanctum group, with the
| api.v1. name prefix already applied.
|
| Ownership checks are model policies (App\Policies, registered in
| PlanningPolicyServiceProvider) used through Gate::authorize() in the
| controllers.
*/

Route::name('planning.')->group(function () {
    // Wholemap (wormhole mapper)
    Route::post('/wholemap/signatures', [WholemapSignatureController::class, 'store'])->name('wholemap.signatures.store');
    Route::post('/wholemap/signatures/paste', [WholemapSignatureController::class, 'paste'])->name('wholemap.signatures.paste');
    Route::put('/wholemap/signatures/{signature}', [WholemapSignatureController::class, 'update'])->name('wholemap.signatures.update');
    Route::post('/wholemap/signatures/{signature}/lock-toggle', [WholemapSignatureController::class, 'lockToggle'])->name('wholemap.signatures.lock-toggle');
    Route::delete('/wholemap/signatures/{signature}', [WholemapSignatureController::class, 'destroy'])->name('wholemap.signatures.destroy');

    Route::post('/wholemap/connections', [WholemapConnectionController::class, 'store'])->name('wholemap.connections.store');
    Route::put('/wholemap/connections/{connection}', [WholemapConnectionController::class, 'update'])->name('wholemap.connections.update');
    Route::delete('/wholemap/connections/{connection}', [WholemapConnectionController::class, 'destroy'])->name('wholemap.connections.destroy');
    Route::post('/wholemap/jump-logs', [WholemapConnectionController::class, 'storeJumpLog'])->name('wholemap.jump-logs.store');

    Route::put('/wholemap/notes', [WholemapNoteController::class, 'upsert'])->name('wholemap.notes.upsert');
    Route::delete('/wholemap/notes/{note}', [WholemapNoteController::class, 'destroy'])->name('wholemap.notes.destroy');

    // Saved plans
    Route::apiResource('invention-plans', InventionPlanController::class)
        ->parameters(['invention-plans' => 'plan'])
        ->names('invention-plans');
    Route::apiResource('reaction-plans', ReactionPlanController::class)
        ->parameters(['reaction-plans' => 'plan'])
        ->names('reaction-plans');

    // Station configs
    Route::get('/station-configs', [StationConfigController::class, 'index'])->name('station-configs.index');
    Route::post('/station-configs', [StationConfigController::class, 'store'])->name('station-configs.store');
    Route::put('/station-configs/{stationConfig}', [StationConfigController::class, 'update'])->name('station-configs.update');
    Route::delete('/station-configs/{stationConfig}', [StationConfigController::class, 'destroy'])->name('station-configs.destroy');
    Route::post('/station-configs/{stationConfig}/default', [StationConfigController::class, 'setDefault'])->name('station-configs.default');

    // Moon scans
    Route::put('/moon-scans/moons/{moonId}/occupation', [MoonOccupationController::class, 'update'])
        ->whereNumber('moonId')
        ->name('moon-scans.occupation');
});
