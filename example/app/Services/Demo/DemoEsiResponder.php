<?php

namespace App\Services\Demo;

use App\Models\Character;
use App\Services\Esi\EsiResponse;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Stands in for ESI when the character belongs to the demo account. Payloads
 * have ESI's shape and are derived from the character id, so a character always
 * sees the same assets, blueprints and planets until the account is regenerated.
 */
class DemoEsiResponder
{
    public const HUBS = [
        ['station_id' => 60003760, 'system_id' => 30000142, 'system' => 'Jita'],
        ['station_id' => 60008494, 'system_id' => 30002187, 'system' => 'Amarr'],
        ['station_id' => 60011866, 'system_id' => 30002659, 'system' => 'Dodixie'],
        ['station_id' => 60004588, 'system_id' => 30002510, 'system' => 'Rens'],
        ['station_id' => 60005686, 'system_id' => 30002053, 'system' => 'Hek'],
    ];

    private const SKILLS = [3380 => 5, 3388 => 4, 3387 => 5, 24625 => 4, 3406 => 5, 24624 => 4, 3402 => 5, 3403 => 5, 3409 => 4, 45746 => 4, 45748 => 4, 45749 => 3, 2406 => 5, 2403 => 4, 2505 => 5, 2495 => 5];

    private const MINERALS = [34 => 4_000_000, 35 => 1_200_000, 36 => 400_000, 37 => 90_000, 38 => 30_000, 39 => 12_000, 40 => 4_000];

    private const ISOTOPES = [17887, 17888, 17889, 16274];

    private const SHIPS = [587, 24702, 24698, 638, 17480, 32880];

    /** @var array<string, array{ecu: int, basic: int, resources: list<int>}> */
    private const PLANET_TYPES = [
        'temperate' => ['ecu' => 3068, 'basic' => 2481, 'resources' => [2305, 2288, 2073]],
        'barren' => ['ecu' => 2848, 'basic' => 2473, 'resources' => [2267, 2288, 2268]],
        'oceanic' => ['ecu' => 3063, 'basic' => 2490, 'resources' => [2268, 2286, 2073]],
        'lava' => ['ecu' => 3062, 'basic' => 2469, 'resources' => [2267, 2272, 2307]],
        'ice' => ['ecu' => 3061, 'basic' => 2493, 'resources' => [2268, 2310, 2309]],
        'gas' => ['ecu' => 3060, 'basic' => 2492, 'resources' => [2310, 2311, 2309]],
        'storm' => ['ecu' => 3067, 'basic' => 2483, 'resources' => [2310, 2311, 2272]],
        'plasma' => ['ecu' => 3064, 'basic' => 2471, 'resources' => [2267, 2272, 2308]],
    ];

    public const CORP_ROLES = ['Director', 'Factory_Manager', 'Accountant', 'Junior_Accountant', 'Project_Manager', 'Station_Manager'];

    /** Skill, scope and role set the demo characters are created with. */
    public const SCOPES = [
        'publicData', 'esi-skills.read_skills.v1', 'esi-skills.read_skillqueue.v1', 'esi-location.read_location.v1',
        'esi-industry.read_character_jobs.v1', 'esi-characters.read_blueprints.v1', 'esi-assets.read_assets.v1',
        'esi-universe.read_structures.v1', 'esi-planets.manage_planets.v1', 'esi-search.search_structures.v1',
        'esi-industry.read_corporation_jobs.v1', 'esi-characters.read_corporation_roles.v1', 'esi-corporations.read_blueprints.v1',
        'esi-assets.read_corporation_assets.v1', 'esi-wallet.read_corporation_wallets.v1', 'esi-corporations.read_projects.v1',
    ];

    public static function handles(string $endpoint): bool
    {
        return preg_match('~/(characters|corporations|alliances|universe/planets)/(\d+)~', $endpoint, $m) === 1
            && DemoIds::isDemo((int) $m[2]);
    }

    public function respond(string $method, string $endpoint, ?Character $character, mixed $params = null): ?EsiResponse
    {
        $path = parse_url($endpoint, PHP_URL_PATH) ?: $endpoint;
        $path = preg_replace('#^/(latest|legacy|dev|v\d+)(?=/)#', '', $path);
        parse_str((string) parse_url($endpoint, PHP_URL_QUERY), $query);

        if ($method === 'post' && rtrim($path, '/') === '/characters/affiliation') {
            return $this->ok($this->affiliations((array) $params));
        }

        if (preg_match('~^/universe/planets/(\d+)/?$~', $path, $m)) {
            return $this->ok($this->planetInfo((int) $m[1]));
        }

        if (preg_match('~^/characters/(\d+)(/.*)?$~', $path, $m)) {
            $id = (int) $m[1];
            $rest = rtrim($m[2] ?? '', '/');
            $char = $character && $character->character_id === $id ? $character : Character::find($id);
            if (! $char) {
                return $this->status(404, ['error' => 'Character not found']);
            }

            return $this->characterEndpoint($char, $rest, $method, $query, $params);
        }

        if (preg_match('~^/alliances/(\d+)/?$~', $path, $m)) {
            $member = Character::where('alliance_id', (int) $m[1])->first();

            return $member
                ? $this->ok(['name' => $member->alliance_name, 'ticker' => DemoNames::ticker((string) $member->alliance_name), 'creator_corporation_id' => $member->corporation_id, 'date_founded' => '2018-06-02T12:00:00Z'])
                : $this->status(404, ['error' => 'Alliance not found']);
        }

        if (preg_match('~^/corporations/(\d+)(/.*)?$~', $path, $m)) {
            return $this->corporationEndpoint((int) $m[1], rtrim($m[2] ?? '', '/'), $character, $query);
        }

        return null;
    }

    private function characterEndpoint(Character $c, string $rest, string $method, array $query, mixed $params): EsiResponse
    {
        $seed = (int) $c->character_id;

        return match (true) {
            $rest === '' => $this->ok(['name' => $c->name, 'corporation_id' => $c->corporation_id, 'alliance_id' => $c->alliance_id, 'birthday' => '2019-03-14T09:21:00Z', 'gender' => 'female', 'race_id' => 1, 'bloodline_id' => 1, 'security_status' => 2.4]),
            $rest === '/skills' => $this->ok($this->skills($c)),
            $rest === '/roles' => $this->ok(['roles' => $c->roles ?? [], 'roles_at_base' => [], 'roles_at_hq' => [], 'roles_at_other' => []]),
            $rest === '/blueprints' => $this->paged($this->blueprints($c), $query),
            $rest === '/assets' => $this->paged($this->assets($c), $query),
            $rest === '/assets/names' => $this->ok([]),
            $rest === '/location' => $this->ok(['solar_system_id' => $this->home($c)['system_id'], 'station_id' => $this->home($c)['station_id']]),
            $rest === '/skillqueue' => $this->ok($this->skillQueue($c)),
            $rest === '/industry/jobs' => $this->ok($this->jobs($c, filter_var($query['include_completed'] ?? false, FILTER_VALIDATE_BOOLEAN))),
            $rest === '/planets' => $this->ok($this->colonies($c)),
            (bool) preg_match('~^/planets/(\d+)$~', $rest, $pm) => $this->ok($this->colonyDetails($c, (int) $pm[1])),
            default => $this->status(404, ['error' => 'Not available in the demo']),
        };
    }

    private function corporationEndpoint(int $corporationId, string $rest, ?Character $character, array $query): EsiResponse
    {
        $members = Character::where('corporation_id', $corporationId)->orderBy('character_id')->get();
        $first = $members->first();
        if (! $first) {
            return $this->status(404, ['error' => 'Corporation not found']);
        }

        return match (true) {
            $rest === '' => $this->ok(['name' => $first->corporation_name, 'ticker' => DemoNames::ticker((string) $first->corporation_name), 'member_count' => 40 + $corporationId % 90, 'ceo_id' => $first->character_id, 'alliance_id' => $first->alliance_id, 'tax_rate' => 0.1, 'date_founded' => '2018-06-02T12:00:00Z']),
            $rest === '/blueprints' => $this->paged($members->flatMap(fn ($m) => $this->blueprints($m, 'corp', 12))->values()->all(), $query),
            $rest === '/assets', $rest === '/assets/names' => $this->paged([], $query),
            $rest === '/industry/jobs' => $this->paged($members->flatMap(fn ($m) => $this->jobs($m, filter_var($query['include_completed'] ?? false, FILTER_VALIDATE_BOOLEAN)))->values()->all(), $query),
            $rest === '/wallets' => $this->ok(array_map(fn ($d) => ['division' => $d, 'balance' => round(mt_rand(50_000_000, 4_000_000_000) / 100, 2)], range(1, 7))),
            (bool) preg_match('~^/wallets/\d+/journal$~', $rest) => $this->paged([], $query),
            $rest === '/projects' => $this->ok(['projects' => [], 'cursor' => (object) []]),
            default => $this->status(404, ['error' => 'Not available in the demo']),
        };
    }

    /** @return array{station_id: int, system_id: int, system: string} */
    public function home(Character $c): array
    {
        return self::HUBS[$c->character_id % count(self::HUBS)];
    }

    private function skills(Character $c): array
    {
        $skills = [];
        $total = 0;
        foreach (self::SKILLS as $id => $level) {
            $sp = (int) (250 * 2 ** (2.5 * ($level - 1)) * 4);
            $total += $sp;
            $skills[] = ['skill_id' => $id, 'active_skill_level' => $level, 'trained_skill_level' => $level, 'skillpoints_in_skill' => $sp];
        }

        return ['skills' => $skills, 'total_sp' => $total + 5_000_000, 'unallocated_sp' => 0];
    }

    private function skillQueue(Character $c): array
    {
        $start = CarbonImmutable::now()->subHours(3);

        return [[
            'queue_position' => 0, 'skill_id' => 45749, 'finished_level' => 4,
            'start_date' => $start->toIso8601ZuluString(), 'finish_date' => $start->addHours(21)->toIso8601ZuluString(),
            'training_start_sp' => 181_000, 'level_start_sp' => 181_000, 'level_end_sp' => 1_024_000,
        ]];
    }

    /** @return list<array{0: int, 1: int}> blueprint type id and the product it makes */
    private function blueprintPool(): array
    {
        return Cache::remember('demo.blueprint_pool', 86400, function () {
            $rows = DB::table('blueprint_activities as b')
                ->join('eve_types as t', 't.type_id', '=', 'b.blueprint_type_id')
                ->where('b.activity_id', 1)->where('t.published', 1)->whereNotNull('t.market_group_id')
                ->orderBy('b.blueprint_type_id')->limit(6000)->get(['b.blueprint_type_id', 'b.products']);
            $candidates = [];
            foreach ($rows as $row) {
                $products = json_decode((string) $row->products, true);
                $productId = (int) ($products[0]['type_id'] ?? 0);
                if ($productId > 0) {
                    $candidates[$productId] = (int) $row->blueprint_type_id;
                }
            }
            $priced = DB::table('market_prices')->whereIn('type_id', array_keys($candidates))->where('sell_min', '>', 0)
                ->pluck('type_id')->all();
            $pool = [];
            foreach (array_unique($priced) as $productId) {
                $pool[] = [$candidates[$productId], (int) $productId];
            }
            usort($pool, fn ($a, $b) => $a[0] <=> $b[0]);

            return array_slice($pool, 0, 400);
        });
    }

    /** Deterministic pick of $count entries from the pool for this character. */
    private function pick(Character $c, int $count, string $salt): array
    {
        $pool = $this->blueprintPool();
        if ($pool === []) {
            return [];
        }
        $picked = [];
        $state = crc32($c->character_id.$salt);
        for ($i = 0; count($picked) < min($count, count($pool)); $i++) {
            $state = ($state * 1103515245 + 12345) & 0x7FFFFFFF;
            $picked[$state % count($pool)] = $pool[$state % count($pool)];
        }

        return array_values($picked);
    }

    private function itemBase(Character $c): int
    {
        return 2_000_000_000_000 + (($c->character_id - DemoIds::CHARACTER_BASE) % 100_000) * 1_000;
    }

    private function blueprints(Character $c, string $salt = 'char', int $count = 30): array
    {
        $home = $this->home($c);
        $out = [];
        foreach ($this->pick($c, $count, $salt) as $i => [$bpId]) {
            $original = $i % 3 !== 2;
            $out[] = [
                'item_id' => $this->itemBase($c) + 500 + $i, 'type_id' => $bpId,
                'location_id' => $home['station_id'], 'location_flag' => 'Hangar',
                'quantity' => $original ? -1 : -2, 'runs' => $original ? -1 : 1 + ($i * 7) % 40,
                'material_efficiency' => $original ? [10, 8, 6, 4, 10][$i % 5] : 0,
                'time_efficiency' => $original ? [20, 16, 12, 8, 20][$i % 5] : 0,
            ];
        }

        return $out;
    }

    private function assets(Character $c): array
    {
        $home = $this->home($c);
        $base = $this->itemBase($c);
        $items = [];
        $n = 0;
        $add = function (int $typeId, int $qty, bool $single = false) use (&$items, &$n, $home, $base) {
            $items[] = [
                'item_id' => $base + $n++, 'type_id' => $typeId, 'quantity' => $qty, 'is_singleton' => $single,
                'location_id' => $home['station_id'], 'location_type' => 'station', 'location_flag' => 'Hangar',
            ];
        };
        foreach (self::MINERALS as $typeId => $qty) {
            $add($typeId, (int) ($qty * (0.4 + (($c->character_id + $typeId) % 100) / 100)));
        }
        foreach (self::ISOTOPES as $typeId) {
            $add($typeId, 20_000 + ($c->character_id + $typeId) % 60_000);
        }
        foreach (self::SHIPS as $k => $typeId) {
            if (($c->character_id + $k) % 2 === 0) {
                $add($typeId, 1, true);
            }
        }
        foreach ($this->pick($c, 12, 'asset') as [, $productId]) {
            $add($productId, 1 + ($c->character_id + $productId) % 40);
        }

        return $items;
    }

    private function jobs(Character $c, bool $includeCompleted): array
    {
        $home = $this->home($c);
        $jobs = [];
        $now = CarbonImmutable::now();
        $anchor = $c->created_at ? CarbonImmutable::parse($c->created_at) : $now->subHours(2);
        foreach ($this->pick($c, 10, 'jobs') as $i => [$bpId, $productId]) {
            $activity = [1, 1, 1, 1, 1, 3, 4, 5, 1, 1][$i];
            $duration = [4, 9, 14, 22, 30, 48, 60, 26, 12, 40][$i] * 3600;
            $start = $anchor->subHours(1 + ($i * 5) % 26);
            $end = $start->addSeconds($duration);
            $status = $end->isPast() ? 'delivered' : 'active';
            if ($status === 'delivered' && ! $includeCompleted) {
                continue;
            }
            $jobs[] = [
                'job_id' => 800_000_000 + ($c->character_id % 1_000_000) * 10 + $i,
                'installer_id' => $c->character_id, 'facility_id' => $home['station_id'], 'station_id' => $home['station_id'],
                'activity_id' => $activity, 'blueprint_id' => $this->itemBase($c) + 500 + $i,
                'blueprint_type_id' => $bpId, 'blueprint_location_id' => $home['station_id'], 'output_location_id' => $home['station_id'],
                'runs' => 5 + $i * 3, 'licensed_runs' => 10, 'cost' => 12_000 + $i * 4_000,
                'status' => $status, 'duration' => $duration,
                'start_date' => $start->toIso8601ZuluString(), 'end_date' => $end->toIso8601ZuluString(),
            ] + ($activity === 1 ? ['product_type_id' => $productId] : []);
        }

        return $jobs;
    }

    /** @return list<array{planet_id: int, system_id: int, type: string}> */
    private function planetsFor(Character $c): array
    {
        $types = array_keys(self::PLANET_TYPES);
        $out = [];
        for ($i = 0; $i < 3; $i++) {
            $hub = self::HUBS[($c->character_id + $i) % count(self::HUBS)];
            $out[] = [
                'planet_id' => DemoIds::PLANET_BASE + (($c->character_id - DemoIds::CHARACTER_BASE) % 10_000) * 10 + $i,
                'system_id' => $hub['system_id'],
                'type' => $types[($c->character_id + $i * 3) % count($types)],
            ];
        }

        return $out;
    }

    private function colonies(Character $c): array
    {
        return array_map(fn ($p) => [
            'planet_id' => $p['planet_id'], 'solar_system_id' => $p['system_id'], 'planet_type' => $p['type'],
            'upgrade_level' => 4, 'num_pins' => 7, 'last_update' => CarbonImmutable::now()->subHours(5)->toIso8601ZuluString(),
        ], $this->planetsFor($c));
    }

    private function colonyDetails(Character $c, int $planetId): array
    {
        $planet = collect($this->planetsFor($c))->firstWhere('planet_id', $planetId);
        if (! $planet) {
            return ['links' => [], 'pins' => [], 'routes' => []];
        }
        $cfg = self::PLANET_TYPES[$planet['type']];
        $now = CarbonImmutable::now();
        $pinBase = $planetId * 10;
        $pins = [['pin_id' => $pinBase + 1, 'type_id' => 2254, 'latitude' => 0.3, 'longitude' => 1.2]];
        $routes = [];
        foreach ([0, 1] as $k) {
            $product = $cfg['resources'][$k % count($cfg['resources'])];
            $pins[] = [
                'pin_id' => $pinBase + 2 + $k, 'type_id' => $cfg['ecu'], 'latitude' => 0.4 + $k / 10, 'longitude' => 1.0 + $k / 10,
                'install_time' => $now->subDays(2)->toIso8601ZuluString(),
                'expiry_time' => $now->addHours(20 + ($planetId % 40) + $k * 30)->toIso8601ZuluString(),
                'last_cycle_start' => $now->subMinutes(30)->toIso8601ZuluString(),
                'extractor_details' => ['product_type_id' => $product, 'cycle_time' => 1800, 'qty_per_cycle' => 4200 + $k * 800, 'head_radius' => 0.05, 'heads' => []],
            ];
            $pins[] = [
                'pin_id' => $pinBase + 4 + $k, 'type_id' => $cfg['basic'], 'latitude' => 0.5, 'longitude' => 1.1 + $k / 10,
                'last_cycle_start' => $now->subMinutes(10)->toIso8601ZuluString(), 'schematic_id' => 65 + $k,
            ];
            $routes[] = ['route_id' => $planetId * 10 + $k, 'source_pin_id' => $pinBase + 2 + $k, 'destination_pin_id' => $pinBase + 4 + $k, 'content_type_id' => $product, 'quantity' => 3000];
        }
        $links = [['source_pin_id' => $pinBase + 1, 'destination_pin_id' => $pinBase + 2, 'link_level' => 5], ['source_pin_id' => $pinBase + 1, 'destination_pin_id' => $pinBase + 3, 'link_level' => 5]];

        return ['links' => $links, 'pins' => $pins, 'routes' => $routes];
    }

    private function planetInfo(int $planetId): array
    {
        $character = Character::query()->where('character_id', '>=', DemoIds::CHARACTER_BASE)->where('character_id', '<', DemoIds::CORPORATION_BASE)->get()
            ->first(fn ($c) => collect($this->planetsFor($c))->contains('planet_id', $planetId));
        $planet = $character ? collect($this->planetsFor($character))->firstWhere('planet_id', $planetId) : null;
        $system = $planet ? collect(self::HUBS)->firstWhere('system_id', $planet['system_id'])['system'] : 'Demo';
        $numeral = ['I', 'II', 'III', 'IV', 'V', 'VI'][$planetId % 6];

        return ['planet_id' => $planetId, 'name' => "{$system} {$numeral}", 'system_id' => $planet['system_id'] ?? 0, 'type_id' => 11, 'position' => ['x' => 0, 'y' => 0, 'z' => 0]];
    }

    private function affiliations(array $ids): array
    {
        return Character::whereIn('character_id', array_map('intval', $ids))->get()->map(fn ($c) => array_filter([
            'character_id' => $c->character_id, 'corporation_id' => $c->corporation_id, 'alliance_id' => $c->alliance_id,
        ]))->values()->all();
    }

    private function paged(array $rows, array $query): EsiResponse
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $chunk = array_slice(array_values($rows), ($page - 1) * 1000, 1000);

        return $this->ok($chunk, ['X-Pages' => (string) max(1, (int) ceil(count($rows) / 1000))]);
    }

    private function ok(array $data, array $headers = []): EsiResponse
    {
        return $this->status(200, $data, $headers);
    }

    private function status(int $status, array $data, array $headers = []): EsiResponse
    {
        $now = now();
        $psr = new PsrResponse($status, $headers + [
            'Content-Type' => 'application/json',
            'Date' => $now->toRfc7231String(),
            'Last-Modified' => $now->toRfc7231String(),
            'Cache-Control' => 'public, max-age=60',
        ], json_encode($data));

        return new EsiResponse(new Response($psr), ['source_updated_at' => $now->timestamp, 'validated_at' => $now->timestamp, 'source_expires_at' => $now->timestamp + 60]);
    }
}
