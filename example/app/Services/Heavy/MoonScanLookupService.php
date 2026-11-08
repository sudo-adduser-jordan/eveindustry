<?php

namespace App\Services\Heavy;

use App\Models\Character;
use App\Models\MoonScanSystem;
use App\Services\MarketService;
use App\Services\MoonScanService;
use Illuminate\Support\Collection;

/**
 * Moon-scan analysis and stored results, scoped to the user's corporation.
 * Shared by the old /api/tools/moon-scan routes and /api/v1/moon-scans.
 *
 * The corporation is the main character's (any character when there is no
 * main); without a character there is no corporation.
 */
class MoonScanLookupService
{
    public const PRICING_MODES = ['raw', 'compressed', 'refined'];

    public function __construct(private MoonScanService $moonScans) {}

    public function corporationIdForUser(int $userId): ?int
    {
        $character = Character::where('user_id', $userId)->where('is_main', true)->first()
            ?? Character::where('user_id', $userId)->first();

        return $character?->corporation_id;
    }

    /**
     * Parse, price and STORE a pasted moon scan (one MoonScanSystem per system
     * and corporation, one MoonScanResult per moon, upserted).
     *
     * @return array{moons: array<int, array<string, mixed>>, errors: list<string>, pricing_mode: string}
     */
    public function analyse(int $userId, string $scanData, ?int $regionId, ?string $pricingMode, bool $useCompressed = false): array
    {
        // pricing_mode takes precedence; use_compressed is a backwards-compat alias
        $pricingMode ??= $useCompressed ? 'compressed' : 'raw';

        $result = $this->moonScans->analyse(
            $scanData,
            $regionId ?? MarketService::DEFAULT_REGION_ID,
            $pricingMode,
            $this->corporationIdForUser($userId),
        );

        return $result + ['pricing_mode' => $pricingMode];
    }

    /**
     * Scanned systems of the user's corporation, by name. Empty without a corporation.
     *
     * @return Collection<int, MoonScanSystem>
     */
    public function systemsForUser(int $userId): Collection
    {
        $corporationId = $this->corporationIdForUser($userId);
        if (! $corporationId) {
            return collect();
        }

        return MoonScanSystem::where('corporation_id', $corporationId)->orderBy('system_name')->get();
    }

    /**
     * The stored system of the user's corporation, by name.
     *
     * @throws HeavyServiceException 422 without a corporation, 404 without stored data
     */
    public function findSystem(int $userId, string $systemName): MoonScanSystem
    {
        $corporationId = $this->corporationIdForUser($userId);
        if (! $corporationId) {
            throw new HeavyServiceException(422, 'No corporation found.');
        }

        $record = MoonScanSystem::where('corporation_id', $corporationId)->where('system_name', $systemName)->first();
        if (! $record) {
            throw new HeavyServiceException(404, 'No scan data found for this system.');
        }

        return $record;
    }

    /**
     * The system's moons by value, re-priced when a (valid) mode other than the
     * stored one is asked for. Any mode parameter present, even the stored
     * mode, re-prices.
     *
     * @return array{pricing_mode: string, moons: list<array<string, mixed>>}
     */
    public function systemResults(MoonScanSystem $system, ?string $requestedMode): array
    {
        $rows = $system->moons()->orderByDesc('total_value')->get();

        $pricingMode = in_array($requestedMode, self::PRICING_MODES, true) ? $requestedMode : $system->pricing_mode;

        if ($pricingMode !== $system->pricing_mode || $requestedMode !== null) {
            $moons = $this->moonScans->repriceSystemMoons($rows, (int) $system->region_id, $pricingMode);
        } else {
            $moons = $rows->map(fn ($row) => [
                'id' => $row->id,
                'name' => $row->moon_name,
                'grade' => $row->grade,
                'total_value' => $row->total_value,
                'ores' => $row->ores,
                'occupied' => (bool) $row->occupied,
                'occupying_corporation_id' => $row->occupying_corporation_id,
                'occupying_corporation_name' => $row->occupying_corporation_name,
            ])->values()->all();
        }

        return ['pricing_mode' => $pricingMode, 'moons' => $moons];
    }
}
