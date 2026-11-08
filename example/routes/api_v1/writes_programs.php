<?php

use App\Http\Controllers\Api\V1\Programs\BuildProgramController;
use App\Http\Controllers\Api\V1\Programs\CorporationBuildProgramController;
use App\Http\Controllers\Api\V1\Programs\IndustryRefreshController;
use App\Http\Controllers\Api\V1\Programs\StockItemController;
use App\Http\Controllers\Api\V1\Programs\StructureController;
use App\Models\BuildProgram;
use App\Models\CorporationBuildProgram;
use App\Models\IndustryStockItem;
use App\Models\StationConfig;
use Illuminate\Support\Facades\Route;

/*
| /api/v1 routes for the "writes_programs" part (see docs/api-v1-migration.md, B5 plan).
| Required from routes/api.php inside the auth:sanctum group, with the
| api.v1. name prefix already applied.
|
| Ownership and role checks are model policies (App\Policies\BuildProgramPolicy,
| CorporationBuildProgramPolicy, StationConfigPolicy, IndustryStockItemPolicy,
| registered in ProgramsPolicyServiceProvider) used through `can:` middleware.
| Stock items also keep the `stock-manager` middleware.
*/

Route::name('programs.')->group(function () {
    // Personal build programs
    Route::post('/build-programs', [BuildProgramController::class, 'store'])
        ->middleware('can:create,'.BuildProgram::class)
        ->name('build-programs.store');
    Route::post('/build-programs/from-reaction-plan', [BuildProgramController::class, 'storeFromReactionPlan'])
        ->middleware('can:create,'.BuildProgram::class)
        ->name('build-programs.store-from-reaction-plan');
    Route::post('/build-programs/merge', [BuildProgramController::class, 'merge'])
        ->middleware('can:merge,'.BuildProgram::class)
        ->name('build-programs.merge');
    Route::put('/build-programs/{program}', [BuildProgramController::class, 'update'])
        ->middleware('can:update,program')
        ->name('build-programs.update');
    Route::patch('/build-programs/{program}/location-filter', [BuildProgramController::class, 'updateLocationFilter'])
        ->middleware('can:update,program')
        ->name('build-programs.location-filter');
    Route::delete('/build-programs/{program}', [BuildProgramController::class, 'destroy'])
        ->middleware('can:delete,program')
        ->name('build-programs.destroy');

    // Corporate build programs
    Route::post('/corporation/build-programs', [CorporationBuildProgramController::class, 'store'])
        ->middleware('can:create,'.CorporationBuildProgram::class)
        ->name('corporation.build-programs.store');
    Route::post('/corporation/build-programs/from-reaction-plan', [CorporationBuildProgramController::class, 'storeFromReactionPlan'])
        ->middleware('can:create,'.CorporationBuildProgram::class)
        ->name('corporation.build-programs.store-from-reaction-plan');
    Route::post('/corporation/build-programs/merge', [CorporationBuildProgramController::class, 'merge'])
        ->middleware('can:merge,'.CorporationBuildProgram::class)
        ->name('corporation.build-programs.merge');
    Route::put('/corporation/build-programs/{program}', [CorporationBuildProgramController::class, 'update'])
        ->middleware('can:update,program')
        ->name('corporation.build-programs.update');
    Route::patch('/corporation/build-programs/{program}/location-filter', [CorporationBuildProgramController::class, 'updateLocationFilter'])
        ->middleware('can:update,program')
        ->name('corporation.build-programs.location-filter');
    Route::delete('/corporation/build-programs/{program}', [CorporationBuildProgramController::class, 'destroy'])
        ->middleware('can:delete,program')
        ->name('corporation.build-programs.destroy');

    // Stock manager items (stock managers only)
    Route::middleware('stock-manager')->group(function () {
        Route::post('/stock/items', [StockItemController::class, 'store'])
            ->middleware('can:create,'.IndustryStockItem::class)
            ->name('stock.items.store');
        Route::put('/stock/items/{item}', [StockItemController::class, 'update'])
            ->name('stock.items.update');
        Route::delete('/stock/items/{item}', [StockItemController::class, 'destroy'])
            ->name('stock.items.destroy');
    });

    // Refresh triggers
    Route::post('/industry/availability/clear', [IndustryRefreshController::class, 'clear'])
        ->name('industry.availability.clear');
    Route::post('/industry/availability/refresh', [IndustryRefreshController::class, 'refreshAvailability'])
        ->name('industry.availability.refresh');
    Route::post('/industry/can-produce/refresh', [IndustryRefreshController::class, 'refreshCanProduce'])
        ->name('industry.can-produce.refresh');

    // Structures
    Route::post('/structures', [StructureController::class, 'store'])
        ->middleware('can:create,'.StationConfig::class)
        ->name('structures.store');
    Route::put('/structures/{structure}', [StructureController::class, 'update'])
        ->middleware('can:update,structure')
        ->name('structures.update');
    Route::delete('/structures/{structure}', [StructureController::class, 'destroy'])
        ->middleware('can:delete,structure')
        ->name('structures.destroy');
    Route::post('/structures/{structure}/default', [StructureController::class, 'setDefault'])
        ->middleware('can:setDefault,structure')
        ->name('structures.default');
});
