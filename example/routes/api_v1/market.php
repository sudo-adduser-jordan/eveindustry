<?php

use App\Http\Controllers\Api\V1\Market\AppraisalController;
use App\Http\Controllers\Api\V1\Market\ContractController;
use App\Http\Controllers\Api\V1\Market\MarketDataController;
use App\Http\Controllers\Api\V1\Market\TradingController;
use Illuminate\Support\Facades\Route;

/*
| /api/v1 routes for the "market" group (see docs/api-v1-migration.md).
| Required from routes/api.php inside the auth:sanctum group, with the
| api.v1. name prefix already applied.
*/

Route::prefix('market')->name('market.')->group(function () {
    Route::get('/station-trading', [TradingController::class, 'stationTrading'])->name('station-trading');
    Route::get('/region-arbitrage', [TradingController::class, 'regionArbitrage'])->name('region-arbitrage');
    Route::post('/appraisals', [AppraisalController::class, 'store'])->name('appraisals.store');
    Route::get('/contracts', [ContractController::class, 'index'])->name('contracts.index');
    Route::get('/contracts/{contractId}', [ContractController::class, 'show'])->whereNumber('contractId')->name('contracts.show');
    Route::get('/prices', [MarketDataController::class, 'prices'])->name('prices');
    Route::get('/history/{typeId}', [MarketDataController::class, 'history'])->whereNumber('typeId')->name('history');
    Route::get('/orders', [MarketDataController::class, 'orders'])->name('orders');
});
