<?php

namespace App\Services\Admin;

use App\Models\Character;
use App\Models\User;

/**
 * Numbers and 30-day series for the admin dashboard.
 */
class AdminStatsService
{
    /**
     * @return array<string, int>
     */
    public function stats(): array
    {
        return [
            'users' => User::count(),
            'characters' => Character::count(),
            'active_users' => User::where('is_active', true)->count(),
            'recent_logins' => User::whereNotNull('last_login_at')
                ->where('last_login_at', '>=', now()->subDays(7))
                ->count(),
            'new_users_week' => User::where('created_at', '>=', now()->subWeek())->count(),
            'new_users_month' => User::where('created_at', '>=', now()->subMonth())->count(),
            'logged_in_24h' => User::whereNotNull('last_login_at')
                ->where('last_login_at', '>=', now()->subDay())
                ->count(),
        ];
    }

    /**
     * New users per day, last 30 days (days without any are left out).
     *
     * @return list<array{date: string, count: int}>
     */
    public function registrationSeries(): array
    {
        return $this->series(
            User::selectRaw('DATE(created_at) as date, COUNT(*) as count')
                ->where('created_at', '>=', now()->subDays(30))
        );
    }

    /**
     * Users whose last login fell on each day, last 30 days.
     *
     * @return list<array{date: string, count: int}>
     */
    public function loginSeries(): array
    {
        return $this->series(
            User::selectRaw('DATE(last_login_at) as date, COUNT(*) as count')
                ->whereNotNull('last_login_at')
                ->where('last_login_at', '>=', now()->subDays(30))
        );
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<User>  $query
     * @return list<array{date: string, count: int}>
     */
    private function series($query): array
    {
        return $query->groupBy('date')->orderBy('date')->get()
            ->map(fn ($row) => ['date' => (string) $row->date, 'count' => (int) $row->count])
            ->all();
    }
}
