<?php

use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MarketFreshnessController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\SessionController;
use Illuminate\Support\Facades\Route;

/*
| Versioned JSON API, mounted at /api/v1 (see bootstrap/app.php).
| Auth is Sanctum stateful SPA cookies on the same origin.
| The older Blade-page JSON endpoints under /api/... stay in routes/web.php.
*/

// Public readiness check for load balancers and the post-deploy smoke test.
Route::get('/health', [HealthController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('api.v1.health');

Route::middleware(['auth:sanctum', 'demo-guard'])->name('api.v1.')->group(function () {
    Route::get('/me', [MeController::class, 'show'])->name('me');
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');
    Route::delete('/account', [\App\Http\Controllers\AccountController::class, 'destroy'])->name('account.destroy');
    Route::post('/characters/{character}/main', [\App\Http\Controllers\Api\V1\CharacterManagementController::class, 'setMain'])->name('characters.main');
    Route::delete('/characters/{character}', [\App\Http\Controllers\Api\V1\CharacterManagementController::class, 'destroy'])->name('characters.destroy');
    Route::get('/notifications', [NotificationController::class, 'show'])->name('notifications.show');
    Route::put('/notifications', [NotificationController::class, 'update'])->name('notifications.update');
    Route::put('/notifications/subscription', [NotificationController::class, 'subscribe'])->name('notifications.subscribe');
    Route::delete('/notifications/subscription', [NotificationController::class, 'unsubscribe'])->name('notifications.unsubscribe');
    Route::post('/notifications/test', [NotificationController::class, 'test'])->middleware('throttle:6,1')->name('notifications.test');
    Route::get('/market/freshness', [MarketFreshnessController::class, 'index'])->name('market.freshness');

    // One file per route group, so each group can move over independently.
    // docs/api-v1-migration.md lists every old /api/... route and its v1 target.
    require __DIR__.'/api_v1/reference.php';
    require __DIR__.'/api_v1/market.php';
    require __DIR__.'/api_v1/corp.php';
    require __DIR__.'/api_v1/calculators.php';
    require __DIR__.'/api_v1/heavy.php';
    require __DIR__.'/api_v1/writes_programs.php';
    require __DIR__.'/api_v1/writes_planning.php';
    require __DIR__.'/api_v1/admin.php';
    require __DIR__.'/api_v1/planetary.php';
    require __DIR__.'/api_v1/industry.php';
});
