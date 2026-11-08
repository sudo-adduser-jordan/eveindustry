<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\Auth\AdminAuthController;
use App\Http\Controllers\Auth\DemoLoginController;
use App\Http\Controllers\Auth\EveSsoController;
use App\Http\Controllers\BlueprintResearchController;
use App\Http\Controllers\CharacterController;
use App\Http\Controllers\DemoImageController;
use App\Http\Controllers\ReactionFormulaResearchController;
use App\Http\Controllers\SpaController;
use App\Models\BuildProgram;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/app/dashboard')->name('home');
Route::get('/welcome', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }
    $sponsors = \App\Models\Sponsor::active()->ordered()->get();

    return view('welcome', compact('sponsors'));
})->name('welcome');
Route::get('/login', fn () => view('auth.login'))->name('login')->middleware('guest');

Route::get('/demo/portrait/{id}.svg', [DemoImageController::class, 'portrait'])->whereNumber('id')->name('demo.portrait');
Route::get('/demo/logo/{id}.svg', [DemoImageController::class, 'logo'])->whereNumber('id')->name('demo.logo');
Route::post('/demo/login', DemoLoginController::class)->middleware(['guest', 'throttle:20,1'])->name('demo.login');

Route::get('/auth/eve/scopes', [EveSsoController::class, 'showScopes'])->name('auth.eve.scopes');
Route::get('/auth/eve', [EveSsoController::class, 'redirect'])->name('auth.eve');
Route::get('/auth/eve/callback', [EveSsoController::class, 'callback'])->name('auth.eve.callback');

Route::middleware('guest')->group(function () {
    Route::get('/admin/login', [AdminAuthController::class, 'showLogin'])->name('admin.login');
    Route::post('/admin/login', [AdminAuthController::class, 'login'])->name('admin.login.submit');
});

Route::post('/logout', [AdminAuthController::class, 'logout'])->name('logout')->middleware('auth');

Route::middleware(['auth', 'demo-guard'])->group(function () {
    Route::redirect('/dashboard', '/app/dashboard')->name('dashboard');

    // React app. Not-yet-built assets 404 from nginx; everything else is the shell.
    Route::get('/app/{path?}', SpaController::class)->where('path', '.*')->name('spa');

    Route::redirect('/characters', '/app/characters')->name('characters.index');
    Route::post('/characters/{character}/set-main', [CharacterController::class, 'setMain'])->name('characters.set-main');
    Route::delete('/characters/{character}', [CharacterController::class, 'destroy'])->name('characters.destroy');

    Route::delete('/account', [AccountController::class, 'destroy'])->name('account.destroy');

    // Ported to the React app; the names stay so old links and redirects work.
    Route::redirect('/assets', '/app/assets')->name('assets.index');
    Route::redirect('/assets/corporation', '/app/assets/corporation')->name('assets.corporation.index');
    Route::redirect('/assets/materials', '/app/assets/materials')->name('assets.materials');
    Route::redirect('/assets/abyssal', '/app/assets/abyssal')->name('assets.abyssal');
    Route::redirect('/assets/ships', '/app/assets/ships')->name('assets.ships');
    Route::post('/assets/refresh', [AssetController::class, 'refresh'])->name('assets.refresh');

    // Ported to the React app; the names stay so old links and redirects work.
    Route::redirect('/industry/availability', '/app/industry/slots')->name('industry.availability');
    Route::redirect('/industry/reprocessing', '/app/industry/reprocessing')->name('industry.reprocessing');
    Route::redirect('/industry/jobs', '/app/industry/jobs')->name('industry.jobs');
    Route::redirect('/industry/can-produce', '/app/industry/can-produce')->name('industry.can-produce');
    Route::redirect('/industry/blueprint-research', '/app/industry/blueprint-research')->name('industry.blueprint-research');
    Route::get('/industry/blueprint-research/export', [BlueprintResearchController::class, 'export'])->name('industry.blueprint-research.export');
    Route::redirect('/industry/reaction-formula-research', '/app/industry/reaction-formula-research')->name('industry.reaction-formula-research');
    Route::get('/industry/reaction-formula-research/export', [ReactionFormulaResearchController::class, 'export'])->name('industry.reaction-formula-research.export');

    Route::redirect('/industry/stock-manager', '/app/industry/stock-manager')->middleware('stock-manager')->name('industry.stock-manager');

    // Ported to the React app; the names stay so old links work.
    Route::redirect('/market/station-trading', '/app/market/station-trading')->name('market.station-trading');
    Route::redirect('/market/region-arbitrage', '/app/market/region-arbitrage')->name('market.region-arbitrage');
    Route::redirect('/planetary', '/app/planetary')->name('planetary.dashboard');
    Route::redirect('/planetary/factory-planner', '/app/planetary/factory-planner')->name('planetary.factory-planner');

    // Corporation tools: ported to the React app; the names stay so old links work.
    Route::redirect('/corporate/donations', '/app/corporate/donations')->name('corporate.donations');
    Route::redirect('/corporate/projects', '/app/corporate/projects')->name('corporate.projects');
    Route::redirect('/corporate/projects/contributors', '/app/corporate/projects/contributors')->name('corporate.projects.contributors');
    Route::redirect('/corporate/build-programs', '/app/corporate/build-programs')->name('corporate.build-programs.index');
    Route::redirect('/corporate/build-programs/{program}', '/app/corporate/build-programs/{program}')->name('corporate.build-programs.show');
    Route::redirect('/corporate/ess-tracker', '/app/corporate/ess-tracker')->name('corporate.ess-tracker.index');
    Route::redirect('/corporate/structure-vulnerability', '/app/corporate/structure-vulnerability')->name('corporate.structure-vulnerability.index');

    Route::redirect('/calculators/manufacturing', '/app/calculators/manufacturing')->name('calculators.manufacturing');

    // Ported to the React app; the names stay so old links and redirects work.
    Route::redirect('/structures', '/app/structures')->name('structures.index');

    Route::redirect('/calculators/reactions', '/app/calculators/reactions')->name('calculators.reactions');

    Route::redirect('/calculators/reaction-planner', '/app/calculators/reaction-planner')->name('calculators.reaction-planner');

    Route::redirect('/calculators/reaction-browser', '/app/calculators/reaction-browser')->name('calculators.reaction-browser');

    Route::redirect('/calculators/manufacturing-browser', '/app/calculators/manufacturing-browser')->name('calculators.manufacturing-browser');

    Route::redirect('/calculators/invention-planner', '/app/calculators/invention-planner')->name('calculators.invention-planner');

    Route::redirect('/build-programs', '/app/build-programs')->name('build-programs.index');
    Route::get('/build-programs/{program}', fn (BuildProgram $program) => redirect('/app/build-programs/'.$program->id))->name('build-programs.show');

    Route::redirect('/tools/contract-appraisal', '/app/tools/contract-appraisal')->name('tools.contract-appraisal');

    Route::redirect('/tools/contract-browser', '/app/tools/contract-browser')->name('tools.contract-browser');
    Route::redirect('/tools/contract-browser/{contractId}', '/app/tools/contract-browser/{contractId}')->name('tools.contract-detail');

    // Ported to the React app; the names stay so old links work.
    Route::redirect('/tools/moon-scan', '/app/tools/moon-scan')->name('tools.moon-scan');
    Route::redirect('/wholemap', '/app/wholemap')->name('wholemap.index');
});

// Ported to the React app (/app/admin/...); the names stay so old links work.
// The admin middleware still guards these, so non-admins land on the dashboard.
Route::prefix('admin')->name('admin.')->middleware(['auth', 'admin'])->group(function () {
    Route::redirect('/dashboard', '/app/admin/dashboard')->name('dashboard');
    Route::redirect('/users', '/app/admin/users')->name('users');
    Route::get('/users/{user}/industry-jobs', fn ($user) => redirect("/app/admin/users/{$user}/industry-jobs"))->name('users.industry-jobs');
    Route::redirect('/sponsors', '/app/admin/sponsors')->name('sponsors.index');
    Route::redirect('/sponsors/create', '/app/admin/sponsors/new')->name('sponsors.create');
    Route::get('/sponsors/{sponsor}/edit', fn ($sponsor) => redirect("/app/admin/sponsors/{$sponsor}"))->name('sponsors.edit');
    Route::redirect('/settings', '/app/admin/settings')->name('settings');
    Route::redirect('/discord-bot', '/app/admin/discord-bot')->middleware('feature:discord_bot')->name('discord-bot');
});
