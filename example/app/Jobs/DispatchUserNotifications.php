<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/** Detects and sends one user's due push notifications. Next scheduler run retries a failure. */
class DispatchUserNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(protected int $userId)
    {
        $this->onQueue('default');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('notifications:'.$this->userId))->dontRelease()->expireAfter(180)];
    }

    public function handle(NotificationDispatcher $dispatcher): void
    {
        $user = User::where('is_active', true)->find($this->userId);

        if ($user) {
            $dispatcher->dispatchForUser($user);
        }
    }
}
