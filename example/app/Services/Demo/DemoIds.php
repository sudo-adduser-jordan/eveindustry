<?php

namespace App\Services\Demo;

/**
 * Demo characters, corporations and alliances use ids in a reserved range far
 * above anything CCP has issued, so a demo id can never match a real entity.
 */
final class DemoIds
{
    public const CHARACTER_BASE = 99_000_000_000;

    public const CORPORATION_BASE = 99_100_000_000;

    public const ALLIANCE_BASE = 99_200_000_000;

    public const PLANET_BASE = 99_250_000_000;

    public const CEILING = 99_300_000_000;

    public static function isDemo(?int $id): bool
    {
        return $id !== null && $id >= self::CHARACTER_BASE && $id < self::CEILING;
    }

    public static function portraitUrl(int $characterId, int $size = 128): string
    {
        return "/demo/portrait/{$characterId}.svg?size={$size}";
    }

    public static function logoUrl(int $entityId, int $size = 64): string
    {
        return "/demo/logo/{$entityId}.svg?size={$size}";
    }
}
