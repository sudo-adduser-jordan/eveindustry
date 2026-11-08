<?php

use App\Http\Controllers\Api\V1\Reference\BlueprintController;
use App\Http\Controllers\Api\V1\Reference\CorporationController;
use App\Http\Controllers\Api\V1\Reference\InventionItemController;
use App\Http\Controllers\Api\V1\Reference\ManufacturingCategoryController;
use App\Http\Controllers\Api\V1\Reference\MarketGroupController;
use App\Http\Controllers\Api\V1\Reference\ReactionFormulaController;
use App\Http\Controllers\Api\V1\Reference\SolarSystemController;
use App\Http\Controllers\Api\V1\Reference\StationController;
use App\Http\Controllers\Api\V1\Reference\TypeController;
use Illuminate\Support\Facades\Route;

/*
| /api/v1 routes for the "reference" group (see docs/api-v1-migration.md).
| Required from routes/api.php inside the auth:sanctum group, with the
| api.v1. name prefix already applied.
*/

Route::name('reference.')->group(function () {
    Route::get('/blueprints', [BlueprintController::class, 'index'])->name('blueprints.index');
    Route::get('/blueprints/{blueprintTypeId}', [BlueprintController::class, 'show'])->whereNumber('blueprintTypeId')->name('blueprints.show');

    Route::get('/types', [TypeController::class, 'index'])->name('types.index');
    Route::get('/types/{typeId}', [TypeController::class, 'show'])->whereNumber('typeId')->name('types.show');

    Route::get('/reactions/formulas', [ReactionFormulaController::class, 'index'])->name('reaction-formulas.index');
    Route::get('/reactions/formulas/{formulaTypeId}', [ReactionFormulaController::class, 'show'])->whereNumber('formulaTypeId')->name('reaction-formulas.show');

    Route::get('/invention/items', [InventionItemController::class, 'index'])->name('invention-items.index');
    Route::get('/invention/items/{typeId}', [InventionItemController::class, 'show'])->whereNumber('typeId')->name('invention-items.show');

    Route::get('/manufacturing/categories', [ManufacturingCategoryController::class, 'index'])->name('manufacturing-categories.index');

    Route::get('/stations', [StationController::class, 'index'])->name('stations.index');
    Route::get('/stations/{stationId}', [StationController::class, 'show'])->whereNumber('stationId')->name('stations.show');

    Route::get('/systems', [SolarSystemController::class, 'index'])->name('systems.index');
    Route::get('/systems/{systemId}', [SolarSystemController::class, 'show'])->whereNumber('systemId')->name('systems.show');
    Route::get('/systems/{systemId}/cost-indices', [SolarSystemController::class, 'costIndices'])->whereNumber('systemId')->name('systems.cost-indices');
    Route::get('/systems/{systemId}/stations', [StationController::class, 'inSystem'])->whereNumber('systemId')->name('systems.stations');

    Route::get('/market-groups', [MarketGroupController::class, 'index'])->name('market-groups.index');
    Route::get('/market-groups/tree', [MarketGroupController::class, 'tree'])->name('market-groups.tree');
    Route::get('/market-groups/{marketGroupId}', [MarketGroupController::class, 'show'])->whereNumber('marketGroupId')->name('market-groups.show');

    Route::get('/corporations', [CorporationController::class, 'index'])->name('corporations.index');
});
