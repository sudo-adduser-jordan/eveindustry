<?php

use App\Http\Controllers\Api\V1\Planetary\PlanetaryController;
use Illuminate\Support\Facades\Route;

/*
| /api/v1 routes for the planetary dashboard (Phase D6). Required from
| routes/api.php inside the auth:sanctum group, with the api.v1. name prefix
| already applied. The factory planner is GET /calculators/factory-planner.
*/

Route::get('/planetary', [PlanetaryController::class, 'show'])->name('planetary.show');
Route::post('/planetary/refresh', [PlanetaryController::class, 'refresh'])
    ->middleware('throttle:6,1')
    ->name('planetary.refresh');
