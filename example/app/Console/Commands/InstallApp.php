<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\SetupStatusService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class InstallApp extends Command
{
    protected $signature = 'app:install
        {--skip-data : Do not import game data}
        {--no-migrate : Do not run migrations}';

    protected $description = 'Idempotent first-run setup: check config, migrate, create the admin and import game data';

    /** Passwords nobody should ever deploy with. */
    private const WEAK_PASSWORDS = ['password', 'password123', 'secret', 'admin', 'changeme'];

    public function handle(SetupStatusService $status): int
    {
        if (! $this->checkConfiguration()) {
            return self::FAILURE;
        }

        if (! $this->option('no-migrate')) {
            $this->info('Running migrations...');
            Artisan::call('migrate', ['--force' => true], $this->output);
        }

        $this->ensureAdmin();

        if ($this->option('skip-data')) {
            return self::SUCCESS;
        }

        if ($status->status()['ready']) {
            $this->info('Game data already imported.');

            return self::SUCCESS;
        }

        $this->importGameData();

        return self::SUCCESS;
    }

    private function checkConfiguration(): bool
    {
        $problems = [];

        if (blank(config('app.key'))) {
            $problems[] = 'APP_KEY is empty. Generate one with `php artisan key:generate --show`.';
        }
        if (blank(config('services.eveonline.client_id')) || blank(config('services.eveonline.client_secret'))) {
            $problems[] = 'EVE_CLIENT_ID and EVE_CLIENT_SECRET are required (create an app at https://developers.eveonline.com).';
        }
        if (config('app.env') === 'production' && config('app.debug')) {
            $problems[] = 'APP_DEBUG must be false in production.';
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        return $problems === [];
    }

    private function ensureAdmin(): void
    {
        if (User::where('is_super_admin', true)->exists()) {
            $this->info('Super admin already exists.');

            return;
        }

        $email = config('install.super_admin.email');
        $password = config('install.super_admin.password');
        $generated = blank($password) || in_array(strtolower((string) $password), self::WEAK_PASSWORDS, true);
        if ($generated) {
            $password = Str::password(24, symbols: false);
        }

        User::create([
            'name' => config('install.super_admin.name'),
            'email' => $email,
            'password' => Hash::make($password),
            'is_admin' => true,
            'is_super_admin' => true,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->info("Super admin created: {$email}");
        if ($generated) {
            $this->warn("Generated admin password (shown once, change it after signing in at /admin/login): {$password}");
        }
    }

    private function importGameData(): void
    {
        // Order matters: types before prices; order sweeps and blueprint
        // chunks are queued, so a running worker must pick them up.
        foreach ([
            ['sde:import', []],
            ['market:update-prices', []],
            ['sde:import-blueprints', []],
            ['market:fetch-region-orders', ['--region' => 10000002]],
        ] as [$command, $arguments]) {
            $this->info("Running {$command}...");
            $code = Artisan::call($command, $arguments, $this->output);
            if ($code !== 0) {
                $this->warn("{$command} exited with code {$code}; the scheduler will retry it.");
            }
        }

        $this->info('Initial import started. Large parts finish in the background; check GET /api/v1/health for progress.');
    }
}
