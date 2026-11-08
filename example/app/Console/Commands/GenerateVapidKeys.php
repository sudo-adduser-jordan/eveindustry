<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GenerateVapidKeys extends Command
{
    protected $signature = 'notifications:vapid-keys';

    protected $description = 'Generate a VAPID key pair for web push (prints it; nothing is written)';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->line('WEBPUSH_VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('WEBPUSH_VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->newLine();
        $this->warn('Keep the private key secret and do not rotate it casually: every subscribed device must re-subscribe afterwards.');

        return self::SUCCESS;
    }
}
