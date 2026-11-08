<?php

use App\Http\Controllers\Api\V1\Calculators\BrowserController;
use App\Http\Controllers\Api\V1\Calculators\CorporateExportController;
use App\Http\Controllers\Api\V1\Calculators\FactoryPlannerController;
use App\Http\Controllers\Api\V1\Calculators\InventionController;
use App\Http\Controllers\Api\V1\Calculators\ManufacturingController;
use App\Http\Controllers\Api\V1\Calculators\ReactionPlannerController;
use App\Http\Controllers\Api\V1\Calculators\ReactionsController;
use Illuminate\Support\Facades\Route;

/*
| /api/v1 routes for the "calculators" group (see docs/api-v1-migration.md).
| Required from routes/api.php inside the auth:sanctum group, with the
| api.v1. name prefix already applied.
*/

Route::prefix('calculators')->name('calculators.')->group(function () {
    Route::post('/manufacturing', [ManufacturingController::class, 'calculate'])->name('manufacturing');
    Route::post('/manufacturing/queue-summary', [ManufacturingController::class, 'queueSummary'])->name('manufacturing.queue-summary');
    Route::get('/manufacturing/skills', [ManufacturingController::class, 'skills'])->name('manufacturing.skills');
    Route::post('/manufacturing/stock', [ManufacturingController::class, 'stock'])->name('manufacturing.stock');

    Route::get('/manufacturing-browser', [BrowserController::class, 'browse'])->name('manufacturing-browser.browse');
    Route::post('/manufacturing-browser', [BrowserController::class, 'calculate'])->name('manufacturing-browser.calculate');

    Route::post('/invention', [InventionController::class, 'calculate'])->name('invention');
    Route::get('/invention/skills', [InventionController::class, 'skills'])->name('invention.skills');
    Route::get('/invention/decryptors', [InventionController::class, 'decryptors'])->name('invention.decryptors');

    Route::post('/reactions', [ReactionsController::class, 'calculate'])->name('reactions');
    Route::get('/reactions/skills', [ReactionsController::class, 'skills'])->name('reactions.skills');

    Route::post('/reaction-browser', [BrowserController::class, 'reactions'])->name('reaction-browser');

    Route::post('/reaction-planner', [ReactionPlannerController::class, 'calculate'])->name('reaction-planner');
    Route::post('/reaction-planner/stock', [ReactionPlannerController::class, 'stock'])->name('reaction-planner.stock');
    Route::post('/reaction-planner/formula-stock', [ReactionPlannerController::class, 'formulaStock'])->name('reaction-planner.formula-stock');

    Route::get('/factory-planner', [FactoryPlannerController::class, 'calculate'])->name('factory-planner');

    Route::get('/corporate-export', [CorporateExportController::class, 'show'])->name('corporate-export');
});
