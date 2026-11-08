<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CorporateDonationConfig;
use App\Services\Demo\DemoIds;
use Carbon\Carbon;

class CorporateDonationService
{
    public function __construct(protected EsiService $esi) {}

    /**
     * Check whether a user has a character with the wallet scope needed to enable this feature.
     */
    public function getUserWalletCharacter(int $userId): ?Character
    {
        return $this->getUserWalletCharacters($userId)->first();
    }

    /**
     * Return all characters belonging to the user that have the corporate wallet scope.
     *
     * @return \Illuminate\Support\Collection<int, Character>
     */
    public function getUserWalletCharacters(int $userId): \Illuminate\Support\Collection
    {
        return Character::where('user_id', $userId)
            ->get()
            ->filter(function (Character $character) {
                $scopes = $character->scopes ?? [];

                return in_array('esi-wallet.read_corporation_wallets.v1', $scopes)
                    && $character->corporation_id;
            })
            ->values();
    }

    /**
     * Check if donations tracking is enabled for the user's corporation.
     */
    public function getConfigForUser(int $userId): ?CorporateDonationConfig
    {
        $characters = Character::where('user_id', $userId)->get();

        foreach ($characters as $character) {
            if ($character->corporation_id) {
                $config = CorporateDonationConfig::findActiveForCorporation($character->corporation_id);
                if ($config) {
                    return $config;
                }
            }
        }

        return null;
    }

    /**
     * Enable donation tracking for a corporation using the given character's credentials.
     */
    public function enable(Character $character, int $userId): CorporateDonationConfig
    {
        return CorporateDonationConfig::updateOrCreate(
            ['corporation_id' => $character->corporation_id],
            [
                'corporation_name' => $character->corporation_name,
                'character_id' => $character->character_id,
                'enabled_by_user_id' => $userId,
                'is_active' => true,
            ]
        );
    }

    /**
     * Disable donation tracking for a corporation.
     */
    public function disable(int $corporationId): void
    {
        CorporateDonationConfig::where('corporation_id', $corporationId)
            ->update(['is_active' => false]);
    }

    /**
     * Get donation summary for a user's corporation wallets over a date range.
     * Uses the provider character from the config for ESI API calls.
     * If $divisionFilter is provided (1–7), only that wallet division is queried.
     */
    public function getDonationsForUser(int $userId, ?string $dateFrom = null, ?string $dateTo = null, ?int $divisionFilter = null): array
    {
        $config = $this->getConfigForUser($userId);

        if (! $config) {
            return $this->emptyResult($dateFrom, $dateTo);
        }

        $providerCharacter = $config->character;
        if (! $providerCharacter) {
            return $this->emptyResult($dateFrom, $dateTo);
        }

        $corporationId = $config->corporation_id;
        $corporationName = $config->corporation_name;

        // Default date range: last 30 days
        try {
            $from = $dateFrom ? Carbon::parse($dateFrom)->startOfDay() : now()->subDays(30)->startOfDay();
            $to = $dateTo ? Carbon::parse($dateTo)->endOfDay() : now()->endOfDay();
        } catch (\Exception) {
            $from = now()->subDays(30)->startOfDay();
            $to = now()->endOfDay();
        }

        // Ensure from <= to
        if ($from->gt($to)) {
            [$from, $to] = [$to->startOfDay(), $from->endOfDay()];
        }

        // Fetch wallet divisions using the provider character
        $walletDivisions = $this->getAccessibleDivisions($providerCharacter, $corporationId);

        // Collect donations from all accessible wallet divisions (optionally filtered)
        $allDonations = [];
        foreach ($walletDivisions as $division) {
            if ($divisionFilter !== null && $division['division'] !== $divisionFilter) {
                continue;
            }
            $journal = $this->esi->getCorporationWalletJournal($providerCharacter, $corporationId, $division['division']);
            if (empty($journal)) {
                continue;
            }

            $donations = $this->filterDonations($journal, $from, $to);
            foreach ($donations as &$donation) {
                $donation['wallet_division'] = $division['division'];
                $donation['wallet_name'] = $division['name'];
            }
            unset($donation);
            $allDonations = array_merge($allDonations, $donations);
        }

        // Aggregate by donator
        $summary = $this->aggregateByDonator($allDonations);

        // Calculate grand total
        $total = array_sum(array_column($summary, 'total_amount'));

        return [
            'donations' => $summary,
            'total' => $total,
            'corporation_name' => $corporationName,
            'corporation_id' => $corporationId,
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'wallet_divisions' => $walletDivisions,
        ];
    }

    /**
     * Get accessible wallet divisions with names.
     */
    protected function getAccessibleDivisions(Character $character, int $corporationId): array
    {
        $wallets = $this->esi->getCorporationWallets($character, $corporationId);
        if (empty($wallets)) {
            // Fall back to just master wallet
            return [['division' => 1, 'name' => 'Master Wallet']];
        }

        $divisions = [];
        foreach ($wallets as $wallet) {
            $div = $wallet['division'] ?? null;
            if ($div !== null) {
                $divisions[] = [
                    'division' => $div,
                    'name' => $div === 1 ? 'Master Wallet' : "Division {$div}",
                    'balance' => $wallet['balance'] ?? 0,
                ];
            }
        }

        return $divisions;
    }

    /**
     * Filter journal entries for player donations within the date range.
     */
    public function filterDonations(array $journal, Carbon $from, Carbon $to): array
    {
        $donations = [];

        foreach ($journal as $entry) {
            // Only include player_donation ref_type
            if (($entry['ref_type'] ?? '') !== 'player_donation') {
                continue;
            }

            // Filter by date range
            $date = isset($entry['date']) ? Carbon::parse($entry['date']) : null;
            if (! $date || $date->lt($from) || $date->gt($to)) {
                continue;
            }

            // Only include positive amounts (incoming donations)
            $amount = $entry['amount'] ?? 0;
            if ($amount <= 0) {
                continue;
            }

            $donations[] = [
                'id' => $entry['id'] ?? null,
                'date' => $date->toDateTimeString(),
                'amount' => $amount,
                'first_party_id' => $entry['first_party_id'] ?? null,
                'first_party_name' => null, // resolved later
                'reason' => $entry['reason'] ?? '',
                'balance' => $entry['balance'] ?? null,
            ];
        }

        return $donations;
    }

    /**
     * Aggregate donations by donator (first_party_id) and resolve names.
     */
    public function aggregateByDonator(array $donations): array
    {
        $grouped = [];

        foreach ($donations as $donation) {
            $donatorId = $donation['first_party_id'];
            if ($donatorId === null) {
                continue;
            }

            if (! isset($grouped[$donatorId])) {
                $grouped[$donatorId] = [
                    'donator_id' => $donatorId,
                    'donator_name' => null,
                    'total_amount' => 0,
                    'donation_count' => 0,
                    'donations' => [],
                ];
            }

            $grouped[$donatorId]['total_amount'] += $donation['amount'];
            $grouped[$donatorId]['donation_count']++;
            $grouped[$donatorId]['donations'][] = $donation;
        }

        // Resolve donator names
        foreach ($grouped as $donatorId => &$group) {
            $charInfo = $this->esi->getCharacter($donatorId);
            $group['donator_name'] = $charInfo['name'] ?? "Character #{$donatorId}";
            $group['portrait_url'] = DemoIds::isDemo((int) $donatorId)
                ? DemoIds::portraitUrl((int) $donatorId, 64)
                : "https://images.evetech.net/characters/{$donatorId}/portrait?size=64";
        }

        // Sort by total amount descending
        usort($grouped, fn ($a, $b) => $b['total_amount'] <=> $a['total_amount']);

        return array_values($grouped);
    }

    /**
     * Clear cached wallet data for a corporation.
     */
    public function clearCorporationCache(int $corporationId): void
    {
        $this->esi->clearCorporationWalletJournalCache($corporationId);
    }

    /**
     * Return an empty result structure.
     */
    protected function emptyResult(?string $dateFrom, ?string $dateTo): array
    {
        try {
            $from = $dateFrom ? Carbon::parse($dateFrom)->startOfDay() : now()->subDays(30)->startOfDay();
            $to = $dateTo ? Carbon::parse($dateTo)->endOfDay() : now()->endOfDay();
        } catch (\Exception) {
            $from = now()->subDays(30)->startOfDay();
            $to = now()->endOfDay();
        }

        return [
            'donations' => [],
            'total' => 0,
            'corporation_name' => null,
            'corporation_id' => null,
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'wallet_divisions' => [],
        ];
    }
}
