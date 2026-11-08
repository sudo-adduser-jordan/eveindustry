<?php

namespace App\Services;

use App\Models\Character;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/** Shared dispatch and execution gates for private ESI datasets. */
class RefreshEligibility
{
    public const INDUSTRY_SCOPES = ['esi-industry.read_character_jobs.v1', 'esi-skills.read_skills.v1'];

    public const PI_SCOPES = ['esi-planets.manage_planets.v1'];

    public static function users(string $dataset): Builder
    {
        return User::query()->where('is_active', true)
            ->where('last_login_at', '>=', now()->subDays(7))
            ->whereHas('characters', fn (Builder $query) => self::constrain($query, $dataset));
    }

    public static function user(int $userId, string $dataset): bool
    {
        return self::users($dataset)->whereKey($userId)->exists();
    }

    public static function characters(int $userId, string $dataset): Builder
    {
        return self::constrain(Character::query()->where('user_id', $userId), $dataset);
    }

    public static function constrain(Builder $query, string $dataset): Builder
    {
        $query->where('is_esi_active', true);

        if ($dataset === 'dashboard') {
            return $query->where(function (Builder $q) {
                $q->where(fn (Builder $q) => self::scopes($q, self::INDUSTRY_SCOPES))
                    ->orWhere(fn (Builder $q) => self::scopes($q, self::PI_SCOPES));
            });
        }

        if ($dataset === 'assets') {
            return $query->where(function (Builder $q) {
                $q->whereJsonContains('scopes', 'esi-assets.read_assets.v1')
                    ->orWhere(function (Builder $q) {
                        $q->whereNotNull('corporation_id')
                            ->whereJsonContains('scopes', 'esi-assets.read_corporation_assets.v1')
                            ->where(fn (Builder $q) => $q->whereJsonContains('roles', 'Director')
                                ->orWhereJsonContains('roles', 'CEO')->orWhere('is_director_ceo', true));
                    });
            });
        }

        return self::scopes($query, match ($dataset) {
            'industry' => self::INDUSTRY_SCOPES,
            'pi' => self::PI_SCOPES,
            default => [],
        });
    }

    private static function scopes(Builder $query, array $scopes): Builder
    {
        foreach ($scopes as $scope) {
            $query->whereJsonContains('scopes', $scope);
        }

        return $query;
    }

    public static function character(Character $character, array $scopes): bool
    {
        return $character->is_esi_active && collect($scopes)->every(fn ($scope) => $character->hasScope($scope));
    }

    /** Roles are the allowed alternatives in the pinned ESI OpenAPI x-required-roles. */
    public static function corporation(Character $character, int $corporationId, string $scope, array $roles = []): bool
    {
        return self::character($character, [$scope])
            && (int) $character->corporation_id === $corporationId
            && ! Cache::has(self::denialKey($character, $corporationId, $scope, $roles))
            && ($roles === [] || collect($roles)->contains(fn ($role) => $character->hasRole($role))
                || (in_array('Director', $roles, true) && ($character->is_director_ceo || $character->hasRole('CEO'))));
    }

    public static function denyCorporation(Character $character, int $corporationId, string $scope, array $roles = []): void
    {
        Cache::put(self::denialKey($character, $corporationId, $scope, $roles), true, 900);
    }

    private static function denialKey(Character $character, int $corporationId, string $scope, array $roles): string
    {
        sort($roles);

        return 'esi.corporation_denied.'.$character->character_id.'.'.$corporationId.'.'.sha1($scope.implode('|', $roles));
    }
}
