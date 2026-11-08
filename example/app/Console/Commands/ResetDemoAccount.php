<?php

namespace App\Console\Commands;

use App\Services\Demo\DemoDataService;
use Illuminate\Console\Command;

class ResetDemoAccount extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Regenerate the demo account with three new characters and fresh data';

    public function handle(DemoDataService $demo): int
    {
        if (! config('demo.enabled')) {
            $this->info('Demo mode is disabled (DEMO_ENABLED).');

            return self::SUCCESS;
        }

        $user = $demo->reset();
        $this->info('Demo account reset with: '.$user->characters()->pluck('name')->implode(', '));

        return self::SUCCESS;
    }
}
