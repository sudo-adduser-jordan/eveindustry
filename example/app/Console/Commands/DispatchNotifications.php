<?php

namespace App\Console\Commands;

use App\Jobs\DispatchUserNotifications;
use App\Models\User;
use App\Services\Notifications\WebPushSender;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchNotifications extends Command
{
    protected $signature = 'notifications:dispatch';

    protected $description = 'Queue push notification checks for users with a subscribed device';

    /** Spread the checks across the schedule interval. */
    private const SPREAD_SECONDS = 240;

    private const LOG_RETENTION_DAYS = 14;

    public function handle(): int
    {
        // The log only protects against repeats inside the lookback window.
        DB::table('notification_log')->where('sent_at', '<', now()->subDays(self::LOG_RETENTION_DAYS))->delete();

        if (! WebPushSender::configured()) {
            return self::SUCCESS;
        }

        $query = User::query()->where('is_active', true)->where('is_demo', false)->whereHas('pushSubscriptions');
        $total = (clone $query)->count();
        $index = 0;

        $query->chunkById(500, function ($users) use (&$index, $total) {
            foreach ($users as $user) {
                $delay = (int) (($index++ / max(1, $total)) * self::SPREAD_SECONDS);
                DispatchUserNotifications::dispatch($user->id)->delay(now()->addSeconds($delay));
            }
        });

        $this->info("Queued notification checks for {$total} users.");

        return self::SUCCESS;
    }
}
