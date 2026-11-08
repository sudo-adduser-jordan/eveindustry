<?php

use App\Http\Controllers\Api\V1\Admin\AccountController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\DiscordBotController;
use App\Http\Controllers\Api\V1\Admin\EsiDiagnosticsController;
use App\Http\Controllers\Api\V1\Admin\SponsorController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use Illuminate\Support\Facades\Route;

/*
| /api/v1/admin: the admin area (phase D6). Required from routes/api.php
| inside the auth:sanctum group; the admin middleware answers 403 JSON for
| non-admins. Super admins cannot be changed or deleted.
*/

Route::prefix('admin')->name('admin.')->middleware('admin')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'show'])->name('dashboard');

    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users/{user}/toggle-status', [UserController::class, 'toggleStatus'])->name('users.toggle-status');
    Route::post('/users/{user}/toggle-admin', [UserController::class, 'toggleAdmin'])->name('users.toggle-admin');
    Route::post('/users/{user}/toggle-stock-manager', [UserController::class, 'toggleStockManager'])->name('users.toggle-stock-manager');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    Route::get('/users/{user}/industry-jobs', [UserController::class, 'industryJobs'])->name('users.industry-jobs');

    Route::get('/sponsors', [SponsorController::class, 'index'])->name('sponsors.index');
    Route::post('/sponsors', [SponsorController::class, 'store'])->name('sponsors.store');
    Route::get('/sponsors/{sponsor}', [SponsorController::class, 'show'])->name('sponsors.show');
    // POST, not PUT: PHP does not parse multipart bodies on PUT.
    Route::post('/sponsors/{sponsor}', [SponsorController::class, 'update'])->name('sponsors.update');
    Route::delete('/sponsors/{sponsor}', [SponsorController::class, 'destroy'])->name('sponsors.destroy');

    Route::get('/account', [AccountController::class, 'show'])->name('account.show');
    Route::put('/account', [AccountController::class, 'update'])->name('account.update');

    Route::prefix('esi-diagnostics')->name('esi-diagnostics.')->group(function () {
        Route::get('/summary', [EsiDiagnosticsController::class, 'summary'])->name('summary');
        Route::get('/trends', [EsiDiagnosticsController::class, 'trends'])->name('trends');
        Route::get('/endpoints', [EsiDiagnosticsController::class, 'endpoints'])->name('endpoints');
        Route::get('/events', [EsiDiagnosticsController::class, 'events'])->name('events');
        Route::get('/batches', [EsiDiagnosticsController::class, 'batches'])->name('batches');
        Route::get('/health', [EsiDiagnosticsController::class, 'health'])->name('health');
    });

    // Work in progress: 404 unless config('features.discord_bot') is on.
    Route::middleware('feature:discord_bot')->group(function () {
        Route::get('/discord-bot', [DiscordBotController::class, 'show'])->name('discord-bot.show');
        Route::put('/discord-bot', [DiscordBotController::class, 'update'])->name('discord-bot.update');
    });
});
