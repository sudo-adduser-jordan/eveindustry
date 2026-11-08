<?php

namespace App\Http\Resources\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin dashboard: counts, 30-day series and the newest users.
 * Built from an array: {stats, registrations, logins, recent_users}.
 */
class AdminDashboardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var array{users: int, characters: int, active_users: int, recent_logins: int, new_users_week: int, new_users_month: int, logged_in_24h: int} */
            'stats' => $this->resource['stats'],
            /** @var list<array{date: string, count: int}> New users per day over 30 days. */
            'registrations' => $this->resource['registrations'],
            /** @var list<array{date: string, count: int}> Users by last login day over 30 days. */
            'logins' => $this->resource['logins'],
            'recent_users' => AdminUserResource::collection($this->resource['recent_users']),
        ];
    }
}
