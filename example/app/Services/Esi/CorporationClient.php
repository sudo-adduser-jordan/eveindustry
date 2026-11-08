<?php

namespace App\Services\Esi;

use App\Models\Character;
use App\Services\Esi\Diagnostics\EsiDiagnosticsContext;
use App\Services\RefreshEligibility;
use Illuminate\Support\Facades\Cache;

/**
 * Corporation and alliance ESI endpoints: info, blueprints, assets, wallets and projects.
 */
class CorporationClient
{
    public function __construct(
        protected EsiHttpClient $http,
        protected EsiAuthClient $auth,
    ) {}

    public function getCorporation(int $corporationId, bool $ignoreCache = false): ?array
    {
        if ($ignoreCache) {
            Cache::forget("esi.corporation.{$corporationId}");
        }

        return Cache::remember("esi.corporation.{$corporationId}", 3600, function () use ($corporationId) {
            $response = $this->http->publicRequest('get', "/corporations/{$corporationId}/");

            return $response?->successful() ? $response->json() : null;
        });
    }

    public function getAlliance(int $allianceId, bool $ignoreCache = false): ?array
    {
        if ($ignoreCache) {
            Cache::forget("esi.alliance.{$allianceId}");
        }

        return Cache::remember("esi.alliance.{$allianceId}", 3600, function () use ($allianceId) {
            $response = $this->http->publicRequest('get', "/alliances/{$allianceId}/");

            return $response?->successful() ? $response->json() : null;
        });
    }

    /**
     * Alias for getCorporation for consistency with Character model usage.
     */
    public function getCorporationInfo(int $corporationId): ?array
    {
        return $this->getCorporation($corporationId);
    }

    /**
     * Alias for getAlliance for consistency with Character model usage.
     */
    public function getAllianceInfo(int $allianceId): ?array
    {
        return $this->getAlliance($allianceId);
    }

    /**
     * Get corporation blueprints from ESI.
     * Requires scope: esi-corporations.read_blueprints.v1 and Director role.
     */
    public function getCorporationBlueprints(Character $character, int $corporationId): array
    {
        if (! RefreshEligibility::corporation($character, $corporationId, 'esi-corporations.read_blueprints.v1', ['Director'])) {
            return [];
        }

        $cacheKey = "esi.corp_blueprints.{$corporationId}";

        return Cache::remember($cacheKey, 3600, function () use ($character, $corporationId) {
            return $this->paginated(
                "/corporations/{$corporationId}/blueprints/",
                $character
            );
        }) ?? [];
    }

    /**
     * Get corporation assets from ESI.
     * Requires scope: esi-assets.read_corporation_assets.v1 and Director role.
     *
     * Eligibility is checked before shared-cache access. A complete empty
     * inventory is cached; transport failures throw and never replace it.
     */
    public function getCorporationAssets(Character $character, int $corporationId): array
    {
        if (! RefreshEligibility::corporation($character, $corporationId, 'esi-assets.read_corporation_assets.v1', ['Director'])) {
            return [];
        }

        $cacheKey = "esi.corp_assets.{$corporationId}";

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $result = $this->paginated(
            "/corporations/{$corporationId}/assets/",
            $character
        );

        Cache::put($cacheKey, $result, 3600);

        return $result;
    }

    /**
     * Get custom names for corporation assets (containers, ships, etc.).
     * Requires scope: esi-assets.read_corporation_assets.v1
     *
     * @param  array<int>  $itemIds  Item IDs to look up names for
     * @return array<int, string> Map of item_id => custom name
     */
    public function getCorporationAssetNames(Character $character, int $corporationId, array $itemIds): array
    {
        if (! RefreshEligibility::corporation($character, $corporationId, 'esi-assets.read_corporation_assets.v1', ['Director'])) {
            return [];
        }

        $names = [];
        foreach (array_chunk($itemIds, EsiHttpClient::ESI_PAGE_SIZE) as $chunk) {
            $entries = app(EsiDiagnosticsContext::class)->with(
                ['dataset' => 'asset_names', 'corporation_id' => $corporationId, 'batch_size' => count($chunk), 'batch_fingerprint' => EsiDiagnosticsContext::fingerprint($chunk)],
                fn () => $this->response('post', "/corporations/{$corporationId}/assets/names/", $character, params: $chunk),
            )->data();
            foreach ($entries ?? [] as $entry) {
                $name = $entry['name'] ?? '';
                if ($name !== '' && $name !== 'None') {
                    $names[$entry['item_id']] = $name;
                }
            }
        }

        return $names;
    }

    /**
     * Get corporation wallet journal entries from ESI.
     * Requires scope: esi-wallet.read_corporation_wallets.v1
     *
     * @param  Character  $character  Character with corporate wallet access
     * @param  int  $corporationId  Corporation ID
     * @param  int  $division  Wallet division (1-7, default 1 = master wallet)
     */
    public function getCorporationWalletJournal(Character $character, int $corporationId, int $division = 1): array
    {
        if (! RefreshEligibility::corporation($character, $corporationId, 'esi-wallet.read_corporation_wallets.v1', ['Accountant', 'Junior_Accountant'])) {
            return [];
        }

        $cacheKey = "esi.corp_wallet_journal.{$corporationId}.{$division}";

        return Cache::remember($cacheKey, 300, function () use ($character, $corporationId, $division) {
            return $this->paginated(
                "/corporations/{$corporationId}/wallets/{$division}/journal/",
                $character
            );
        }) ?? [];
    }

    /**
     * Clear cached corporation wallet journal entries.
     */
    public function clearCorporationWalletJournalCache(int $corporationId): void
    {
        for ($division = 1; $division <= 7; $division++) {
            Cache::forget("esi.corp_wallet_journal.{$corporationId}.{$division}");
        }
    }

    /**
     * Get corporation wallet divisions from ESI.
     * Requires scope: esi-wallet.read_corporation_wallets.v1
     */
    public function getCorporationWallets(Character $character, int $corporationId): ?array
    {
        if (! RefreshEligibility::corporation($character, $corporationId, 'esi-wallet.read_corporation_wallets.v1', ['Accountant', 'Junior_Accountant'])) {
            return null;
        }

        $cacheKey = "esi.corp_wallets.{$corporationId}";

        return Cache::remember($cacheKey, 300, function () use ($character, $corporationId) {
            return $this->response(
                'get',
                "/corporations/{$corporationId}/wallets/",
                $character
            )->data();
        });
    }

    /**
     * Get all corporation projects from ESI using cursor-based pagination.
     * Requires scope: esi-corporations.read_projects.v1
     */
    public function getCorporationProjects(Character $character, int $corporationId): array
    {
        if (! RefreshEligibility::corporation($character, $corporationId, 'esi-corporations.read_projects.v1', [])) {
            return [];
        }

        $cacheKey = "esi.corp_projects.{$corporationId}";

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey) ?? [];
        }

        $projects = $this->fetchAllCorporationProjects($character, $corporationId);

        Cache::put($cacheKey, $projects, 300);

        return $projects;
    }

    /**
     * Fetch all pages of corporation projects using cursor-based pagination.
     * Any failed page aborts collection before publication.
     */
    protected function fetchAllCorporationProjects(Character $character, int $corporationId): array
    {
        return $this->cursorPages(
            $character, "/corporations/{$corporationId}/projects", 'projects', 'id', ['state' => 'All']
        );
    }

    /** Walk opaque cursors until exhausted; never publish a prefix on any failure. */
    private function cursorPages(Character $character, string $endpoint, string $field, string $idField, array $params = []): array
    {
        $all = [];
        $seen = [];
        $after = '0';

        while (true) {
            if (isset($seen[$after])) {
                throw new \RuntimeException('ESI cursor repeated before pagination completed');
            }
            $seen[$after] = true;
            $response = $this->response(
                'get', $endpoint, $character, params: array_merge($params, ['after' => $after, 'limit' => 100])
            )->data();
            $page = $response[$field] ?? (is_array($response) && array_is_list($response) ? $response : null);
            if (! is_array($page)) {
                throw new \RuntimeException('ESI cursor response has no valid '.$field.' list');
            }
            foreach ($page as $entry) {
                $id = $entry[$idField] ?? $entry['project_id'] ?? null;
                if ($id === null) {
                    throw new \RuntimeException('ESI cursor record has no identity');
                }
                $all[(string) $id] = $entry;
            }

            $next = $response['cursor']['after'] ?? null;
            if ($next === null || $next === '') {
                return array_values($all);
            }
            if (! is_string($next)) {
                throw new \RuntimeException('ESI cursor has an invalid value');
            }
            $after = $next;
        }
    }

    /**
     * Get contributors for a specific corporation project.
     * Requires scope: esi-corporations.read_projects.v1
     */
    public function getCorporationProjectContributors(Character $character, int $corporationId, string $projectId): array
    {
        if (! RefreshEligibility::corporation($character, $corporationId, 'esi-corporations.read_projects.v1', ['Project_Manager'])) {
            return [];
        }

        $cacheKey = "esi.corp_project_contributors.{$corporationId}.{$projectId}";

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey) ?? [];
        }

        $contributors = $this->cursorPages(
            $character, "/corporations/{$corporationId}/projects/{$projectId}/contributors", 'contributors', 'character_id'
        );

        Cache::put($cacheKey, $contributors, 300);

        return $contributors;
    }

    /**
     * Clear the cached corporation projects for a given corporation.
     */
    public function clearCorporationProjectsCache(int $corporationId): void
    {
        Cache::forget("esi.corp_projects.{$corporationId}");
    }

    /**
     * Clear the cached contributors for a specific project.
     */
    public function clearCorporationProjectContributorsCache(int $corporationId, string $projectId): void
    {
        Cache::forget("esi.corp_project_contributors.{$corporationId}.{$projectId}");
    }

    private function response(string $method, string $endpoint, Character $character, bool $retried = false, ?array $params = null, array $headers = []): EsiResponse
    {
        return $this->withDenialTracking($character, $endpoint, fn () => $this->http->authenticatedResponse(
            $method, $endpoint, $character, $retried, $params, $headers
        ));
    }

    private function paginated(string $endpoint, Character $character): array
    {
        return $this->withDenialTracking($character, $endpoint, fn () => $this->http->authenticatedPaginatedRequest($endpoint, $character));
    }

    private function withDenialTracking(Character $character, string $endpoint, callable $fetch): mixed
    {
        try {
            return $fetch();
        } catch (EsiRequestException $e) {
            if (in_array($e->outcome, ['forbidden', 'missing_scope'], true)
                && preg_match('~/corporations/(\\d+)/~', $endpoint, $match)) {
                $scope = match (true) {
                    str_contains($endpoint, '/assets') => 'esi-assets.read_corporation_assets.v1',
                    str_contains($endpoint, '/blueprints') => 'esi-corporations.read_blueprints.v1',
                    str_contains($endpoint, '/wallets') => 'esi-wallet.read_corporation_wallets.v1',
                    default => 'esi-corporations.read_projects.v1',
                };
                $roles = match (true) {
                    str_contains($endpoint, '/assets'), str_contains($endpoint, '/blueprints') => ['Director'],
                    str_contains($endpoint, '/wallets') => ['Accountant', 'Junior_Accountant'],
                    str_contains($endpoint, '/contributors') => ['Project_Manager'],
                    default => [],
                };
                RefreshEligibility::denyCorporation($character, (int) $match[1], $scope, $roles);
            }
            throw $e;
        }
    }
}
