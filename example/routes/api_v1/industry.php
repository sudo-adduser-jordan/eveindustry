<?php

use App\Http\Controllers\Api\V1\Industry\CanProduceController;
use App\Http\Controllers\Api\V1\Industry\DashboardController;
use App\Http\Controllers\Api\V1\Industry\JobsOverviewController;
use App\Http\Controllers\Api\V1\Industry\ResearchController;
use App\Http\Controllers\Api\V1\Industry\StockReportController;
use App\Http\Controllers\Api\V1\Programs\BuildProgramController;
use Illuminate\Support\Facades\Route;

/*
| /api/v1 read endpoints for the React industry pages (Phase D, slice 6):
| dashboard, jobs overview, can produce, blueprint and reaction formula
| research, the stock manager report and the personal build program list.
| Required from routes/api.php inside the auth:sanctum group, with the
| api.v1. name prefix already applied.
*/

Route::name('industry-pages.')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'show'])->name('dashboard');

    Route::get('/industry/jobs', [JobsOverviewController::class, 'show'])->name('jobs');
    Route::get('/industry/can-produce', [CanProduceController::class, 'index'])->name('can-produce');
    Route::get('/industry/blueprint-research', [ResearchController::class, 'blueprints'])->name('blueprint-research');
    Route::get('/industry/reaction-formula-research', [ResearchController::class, 'formulas'])->name('reaction-formula-research');

    Route::middleware('stock-manager')->group(function () {
        Route::get('/stock', [StockReportController::class, 'index'])->name('stock');
        Route::post('/stock/refresh', [StockReportController::class, 'refresh'])->name('stock.refresh');
    });

    Route::get('/build-programs', [BuildProgramController::class, 'index'])->name('build-programs.index');
    Route::get('/build-programs/{program}', [BuildProgramController::class, 'show'])
        ->middleware('can:view,program')
        ->name('build-programs.show');
});
