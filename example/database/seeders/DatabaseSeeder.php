<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Create super admin
        $email = config('install.super_admin.email');

        if (!User::where('email', $email)->exists()) {
            $password = config('install.super_admin.password') ?: Str::password(24, symbols: false);

            User::create([
                'name' => config('install.super_admin.name'),
                'email' => $email,
                'password' => Hash::make($password),
                'is_admin' => true,
                'is_super_admin' => true,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);

            $this->command->info('Super Admin created: ' . $email);
            if (blank(config('install.super_admin.password'))) {
                $this->command->warn('Generated admin password (shown once): ' . $password);
            }
        } else {
            $this->command->info('Super Admin already exists.');
        }
    }
}
