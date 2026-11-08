<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ResetAdmin extends Command
{
    protected $signature = 'admin:reset';

    protected $description = 'Reset the super admin to the SUPER_ADMIN_* settings (a random password is printed if none is set)';

    public function handle(): int
    {
        $name = config('install.super_admin.name');
        $email = config('install.super_admin.email');
        $password = config('install.super_admin.password') ?: Str::password(24, symbols: false);
        $generated = blank(config('install.super_admin.password'));

        $admin = User::where('email', $email)->first()
            ?? User::where('is_super_admin', true)->first();

        if (!$admin) {
            $admin = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'is_admin' => true,
                'is_super_admin' => true,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);

            $this->info("Super admin created: {$email}");
            if ($generated) {
                $this->warn("Generated admin password (shown once): {$password}");
            }

            return self::SUCCESS;
        }

        $admin->update([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'is_active' => true,
        ]);

        $this->info("Super admin reset: {$email}");
        if ($generated) {
            $this->warn("Generated admin password (shown once): {$password}");
        }

        return self::SUCCESS;
    }
}
