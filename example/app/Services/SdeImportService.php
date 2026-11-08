<?php

namespace App\Services;

use App\Models\BlueprintActivity;
use App\Models\EveType;
use App\Models\InvCategory;
use App\Models\InvGroup;
use App\Models\MarketGroup;
use App\Models\SdeSolarSystem;
use App\Models\SdeStargate;
use App\Models\SdeStation;
use App\Models\TypeMaterial;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ZipArchive;

class SdeImportService
{
    /** URL of the official CCP Static Data Export (JSONL ZIP). */
    public const SDE_BASE_URL = 'https://developers.eveonline.com/static-data/eve-online-static-data-latest-jsonl.zip';

    /** Cache key that stores the last successfully imported SDE checksum. */
    public const SDE_CHECKSUM_CACHE_KEY = 'sde.last_checksum';

    /**
     * Maps the string activity keys in blueprints.jsonl
     * to the numeric IDs stored in blueprint_activities.
     */
    public const ACTIVITY_MAP = [
        'manufacturing' => BlueprintActivity::ACTIVITY_MANUFACTURING,   // 1
        'research_time' => 3,
        'research_material' => 4,
        'copying' => BlueprintActivity::ACTIVITY_COPYING,          // 5
        'reverse_engineering' => 7,
        'invention' => BlueprintActivity::ACTIVITY_INVENTION,        // 8
        'reaction' => BlueprintActivity::ACTIVITY_REACTION,         // 11
    ];

    /** Number of rows written per upsert call. */
    protected const CHUNK_SIZE = 1000;

    /** Path to the downloaded SDE ZIP file (set by downloadZip). */
    protected ?string $zipPath = null;

    // ──────────────────────────────────────────────────────────────────
    // Checksum helpers
    // ──────────────────────────────────────────────────────────────────

    /**
     * Compute a checksum for the remote SDE ZIP by issuing a HEAD request
     * to the official CCP download URL and hashing the ETag, Last-Modified,
     * and Content-Length headers.  This avoids any third-party dependency.
     *
     * Returns null when the request fails so callers can treat it as "unknown".
     */
    public function getRemoteChecksum(): ?string
    {
        try {
            $response = Http::timeout(30)->head(self::SDE_BASE_URL);

            if (! $response->successful()) {
                Log::warning('SDE: Failed to fetch headers for checksum — HTTP '.$response->status());

                return null;
            }

            $etag = $response->header('ETag', '');
            $lastModified = $response->header('Last-Modified', '');
            $contentLength = $response->header('Content-Length', '');

            // All three being empty means we can't reliably detect changes.
            if ($etag === '' && $lastModified === '' && $contentLength === '') {
                Log::warning('SDE: No ETag, Last-Modified, or Content-Length headers available for checksum');

                return null;
            }

            return hash('sha256', $etag.'|'.$lastModified.'|'.$contentLength);
        } catch (\Exception $e) {
            Log::error('SDE: Error fetching checksum: '.$e->getMessage());

            return null;
        }
    }

    /** Return the checksum stored from the last successful import. */
    public function getStoredChecksum(): ?string
    {
        return Cache::get(self::SDE_CHECKSUM_CACHE_KEY);
    }

    /**
     * Persist a checksum after a successful import.
     * Stored indefinitely — only replaced when a new import succeeds.
     */
    public function storeChecksum(string $checksum): void
    {
        Cache::forever(self::SDE_CHECKSUM_CACHE_KEY, $checksum);
    }

    /**
     * Return true when the remote SDE checksum differs from the stored one,
     * indicating new data is available.  Returns false if the remote checksum
     * cannot be fetched (treat failures conservatively — don't re-import).
     */
    public function hasUpdate(): bool
    {
        $remote = $this->getRemoteChecksum();
        if ($remote === null) {
            return false;
        }

        return $remote !== $this->getStoredChecksum();
    }

    // ──────────────────────────────────────────────────────────────────
    // ZIP download / cleanup
    // ──────────────────────────────────────────────────────────────────

    /**
     * Download the SDE ZIP file to a temporary location.
     *
     * @return bool True on success, false on failure.
     */
    public function downloadZip(): bool
    {
        $this->zipPath = storage_path('app/sde-latest.zip');

        try {
            Log::info('SDE: Downloading ZIP from '.self::SDE_BASE_URL);

            $response = Http::timeout(600)->withOptions([
                'sink' => $this->zipPath,
            ])->get(self::SDE_BASE_URL);

            if (! $response->successful()) {
                Log::warning('SDE: Failed to download ZIP — HTTP '.$response->status());
                $this->zipPath = null;

                return false;
            }

            $size = filesize($this->zipPath);
            Log::info("SDE: Downloaded ZIP ({$size} bytes) to {$this->zipPath}");

            return true;
        } catch (\Exception $e) {
            Log::error('SDE: Error downloading ZIP: '.$e->getMessage());
            $this->zipPath = null;

            return false;
        }
    }

    /**
     * Remove the temporary ZIP file.
     */
    public function cleanupZip(): void
    {
        if ($this->zipPath && file_exists($this->zipPath)) {
            @unlink($this->zipPath);
            Log::info('SDE: Cleaned up temporary ZIP file');
        }
        $this->zipPath = null;
    }

    /**
     * Set the ZIP path manually (e.g. for testing or pre-downloaded files).
     */
    public function setZipPath(string $path): void
    {
        $this->zipPath = $path;
    }

    // ──────────────────────────────────────────────────────────────────
    // JSONL reader
    // ──────────────────────────────────────────────────────────────────

    /**
     * Read a JSONL entry from the SDE ZIP line-by-line and invoke a callback
     * for each decoded row.  This is memory-efficient — only one line is held
     * in memory at a time.
     *
     * @param  string  $entryName  Filename inside the ZIP (e.g. "types.jsonl")
     * @param  callable  $callback  fn(array $row): void — receives the decoded JSON object
     * @return int Number of lines processed.
     */
    protected function readJsonlFromZip(string $entryName, callable $callback): int
    {
        if (! $this->zipPath || ! file_exists($this->zipPath)) {
            Log::error('SDE: ZIP file not available — call downloadZip() first');

            return 0;
        }

        $zip = new ZipArchive;
        $result = $zip->open($this->zipPath, ZipArchive::RDONLY);

        if ($result !== true) {
            Log::error("SDE: Failed to open ZIP file (error code {$result})");

            return 0;
        }

        // Try the entry name as-is, then with common prefixes
        $index = $zip->locateName($entryName);
        if ($index === false) {
            $index = $zip->locateName('sde/'.$entryName);
        }
        if ($index === false) {
            // Search by basename as a last resort
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (basename($name) === $entryName) {
                    $index = $i;
                    break;
                }
            }
        }

        if ($index === false) {
            Log::warning("SDE: Entry '{$entryName}' not found in ZIP");
            $zip->close();

            return 0;
        }

        $stream = $zip->getStream($zip->getNameIndex($index));
        if (! $stream) {
            Log::error("SDE: Could not open stream for '{$entryName}'");
            $zip->close();

            return 0;
        }

        $lineCount = 0;

        while (($line = fgets($stream)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $row = json_decode($line, true);
            if (! is_array($row)) {
                continue;
            }

            $callback($row);
            $lineCount++;
        }

        fclose($stream);
        $zip->close();

        return $lineCount;
    }

    /**
     * Extract a localized string value, preferring English.
     *
     * The JSONL SDE uses objects like {"en": "Tritanium", "de": "Tritanium", ...}
     * for names and descriptions.  This helper extracts the English value,
     * falling back to the first available language or a default string.
     */
    protected function localizedString($value, string $default = 'Unknown'): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            return $value['en'] ?? reset($value) ?: $default;
        }

        return $default;
    }

    // ──────────────────────────────────────────────────────────────────
    // Per-dataset importers
    // ──────────────────────────────────────────────────────────────────

    /**
     * Import item types from types.jsonl into the eve_types table.
     *
     * JSONL format (one per line):
     * {"_key": 34, "name": {"en": "Tritanium"}, "groupID": 18, "published": true, ...}
     *
     * @return int Number of rows upserted.
     */
    public function importTypes(): int
    {
        $count = 0;
        $batch = [];
        $now = now();

        $this->readJsonlFromZip('types.jsonl', function (array $row) use (&$count, &$batch, $now) {
            $typeId = (int) ($row['_key'] ?? 0);
            if (! $typeId) {
                return;
            }

            $batch[] = [
                'type_id' => $typeId,
                'name' => $this->localizedString($row['name'] ?? null),
                'description' => $this->localizedString($row['description'] ?? null, ''),
                'group_id' => $row['groupID'] ?? null,
                'market_group_id' => $row['marketGroupID'] ?? null,
                'volume' => $row['volume'] ?? null,
                'packaged_volume' => $row['packagedVolume'] ?? null,
                'mass' => $row['mass'] ?? null,
                'capacity' => $row['capacity'] ?? null,
                'portion_size' => $row['portionSize'] ?? null,
                'icon_id' => $row['iconID'] ?? null,
                'graphic_id' => $row['graphicID'] ?? null,
                'meta_group_id' => $row['metaGroupID'] ?? null,
                'published' => (bool) ($row['published'] ?? false),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= self::CHUNK_SIZE) {
                EveType::upsert($batch, ['type_id'], [
                    'name', 'description', 'group_id', 'market_group_id',
                    'volume', 'packaged_volume', 'mass', 'capacity',
                    'portion_size', 'icon_id', 'graphic_id', 'meta_group_id', 'published', 'updated_at',
                ]);
                $count += count($batch);
                $batch = [];
            }
        });

        if (! empty($batch)) {
            EveType::upsert($batch, ['type_id'], [
                'name', 'description', 'group_id', 'market_group_id',
                'volume', 'packaged_volume', 'mass', 'capacity',
                'portion_size', 'icon_id', 'graphic_id', 'meta_group_id', 'published', 'updated_at',
            ]);
            $count += count($batch);
        }

        Log::info("SDE: Imported {$count} item types from types.jsonl");

        return $count;
    }

    /**
     * Import NPC stations from npcStations.jsonl into the sde_stations table.
     *
     * JSONL format (one per line):
     * {"_key": 60000004, "solarSystemID": 30002780, "typeID": 1531, "ownerID": 1000002,
     *  "reprocessingEfficiency": 0.5, ...}
     *
     * Note: npcStations.jsonl does not include station names, constellation IDs,
     * or region IDs.  Constellation/region are resolved from the sde_solar_systems
     * table (import systems first).  Station name uses the type name if available.
     *
     * @return int Number of rows upserted.
     */
    public function importStations(): int
    {
        // Pre-load solar system → constellation/region/name mappings
        $systemMap = SdeSolarSystem::select('solar_system_id', 'solar_system_name', 'constellation_id', 'region_id', 'security')
            ->get()
            ->keyBy('solar_system_id')
            ->all();

        // Pre-load type names for station naming
        $typeNames = EveType::pluck('name', 'type_id')->all();

        $count = 0;
        $batch = [];
        $now = now();

        $this->readJsonlFromZip('npcStations.jsonl', function (array $row) use (&$count, &$batch, $now, $systemMap, $typeNames) {
            $stationId = (int) ($row['_key'] ?? 0);
            if (! $stationId) {
                return;
            }

            $solarSystemId = $row['solarSystemID'] ?? null;
            $system = $systemMap[$solarSystemId] ?? null;

            // Build station name from available data
            $stationName = $this->localizedString($row['name'] ?? null, '');
            if ($stationName === '') {
                // Fall back to station type name + system info
                $typeName = $typeNames[$row['typeID'] ?? 0] ?? null;
                $systemName = $system ? $system->solar_system_name : null;
                if ($typeName && $systemName) {
                    $stationName = "{$systemName} - {$typeName}";
                } else {
                    $stationName = "Station #{$stationId}";
                }
            }

            $batch[] = [
                'station_id' => $stationId,
                'station_name' => $stationName,
                'solar_system_id' => $solarSystemId,
                'constellation_id' => $system->constellation_id ?? ($row['constellationID'] ?? null),
                'region_id' => $system->region_id ?? ($row['regionID'] ?? null),
                'station_type_id' => $row['typeID'] ?? null,
                'corporation_id' => $row['ownerID'] ?? ($row['corporationID'] ?? null),
                'reprocessing_efficiency' => $row['reprocessingEfficiency'] ?? null,
                'security' => $system->security ?? ($row['security'] ?? null),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= self::CHUNK_SIZE) {
                SdeStation::upsert($batch, ['station_id'], [
                    'station_name', 'solar_system_id', 'constellation_id', 'region_id',
                    'station_type_id', 'corporation_id', 'reprocessing_efficiency',
                    'security', 'updated_at',
                ]);
                $count += count($batch);
                $batch = [];
            }
        });

        if (! empty($batch)) {
            SdeStation::upsert($batch, ['station_id'], [
                'station_name', 'solar_system_id', 'constellation_id', 'region_id',
                'station_type_id', 'corporation_id', 'reprocessing_efficiency',
                'security', 'updated_at',
            ]);
            $count += count($batch);
        }

        Log::info("SDE: Imported {$count} NPC stations from npcStations.jsonl");

        // ── Resolve proper station names via ESI POST /universe/names/ ──
        // npcStations.jsonl does not include station names; the fallback above
        // builds "{system} - {type}" which omits planet/moon info.  The ESI
        // endpoint returns the canonical names like
        // "Jita IV - Moon 4 - Caldari Navy Assembly Plant".
        $this->resolveStationNamesViaEsi();

        return $count;
    }

    /**
     * Batch-resolve proper station names from ESI POST /universe/names/.
     *
     * Processes all stations in the sde_stations table in chunks of 1000
     * (the ESI per-request limit) and updates station_name with the
     * canonical EVE name.
     */
    protected function resolveStationNamesViaEsi(): void
    {
        $esi = app(EsiService::class);
        $updated = 0;
        $now = now();

        SdeStation::select('station_id')
            ->orderBy('station_id')
            ->chunk(1000, function ($stations) use ($esi, &$updated, $now) {
                $ids = $stations->pluck('station_id')->map(fn ($id) => (int) $id)->all();

                $names = $esi->resolveUniverseNames($ids);
                if (empty($names)) {
                    return;
                }

                // Build batch of station_id + station_name rows for upsert
                $batch = [];
                foreach ($names as $entry) {
                    if (($entry['category'] ?? '') === 'station' && ! empty($entry['name'])) {
                        $batch[] = [
                            'station_id' => (int) $entry['id'],
                            'station_name' => $entry['name'],
                            'updated_at' => $now,
                        ];
                    }
                }

                if (! empty($batch)) {
                    SdeStation::upsert($batch, ['station_id'], ['station_name', 'updated_at']);
                    $updated += count($batch);
                }
            });

        Log::info("SDE: Resolved {$updated} station names via ESI /universe/names/");
    }

    /**
     * Import solar systems from mapSolarSystems.jsonl into the sde_solar_systems table.
     *
     * JSONL format (one per line):
     * {"_key": 30000001, "name": {"en": "Tanoo"}, "constellationID": 20000001,
     *  "regionID": 10000001, "security": 0.8574, "securityClass": "B", ...}
     *
     * @return int Number of rows upserted.
     */
    public function importSolarSystems(): int
    {
        $count = 0;
        $batch = [];
        $now = now();

        $this->readJsonlFromZip('mapSolarSystems.jsonl', function (array $row) use (&$count, &$batch, $now) {
            $systemId = (int) ($row['_key'] ?? 0);
            if (! $systemId) {
                return;
            }

            $name = $this->localizedString(
                $row['name'] ?? $row['solarSystemName'] ?? null
            );

            $batch[] = [
                'solar_system_id' => $systemId,
                'solar_system_name' => $name,
                'constellation_id' => $row['constellationID'] ?? null,
                'region_id' => $row['regionID'] ?? null,
                'security' => $row['security'] ?? null,
                'security_class' => $row['securityClass'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= self::CHUNK_SIZE) {
                SdeSolarSystem::upsert($batch, ['solar_system_id'], [
                    'solar_system_name', 'constellation_id', 'region_id',
                    'security', 'security_class', 'updated_at',
                ]);
                $count += count($batch);
                $batch = [];
            }
        });

        if (! empty($batch)) {
            SdeSolarSystem::upsert($batch, ['solar_system_id'], [
                'solar_system_name', 'constellation_id', 'region_id',
                'security', 'security_class', 'updated_at',
            ]);
            $count += count($batch);
        }

        Log::info("SDE: Imported {$count} solar systems from mapSolarSystems.jsonl");

        return $count;
    }

    /**
     * Import stargates from mapStargates.jsonl into the sde_stargates table.
     *
     * JSONL format (one per line):
     * {"_key": 50000001, "solarSystemID": 30000001, "typeID": 29624,
     *  "destination": {"stargateID": 50000002}, ...}
     *
     * @return int Number of rows upserted.
     */
    public function importStargates(): int
    {
        $count = 0;
        $batch = [];
        $now = now();

        $this->readJsonlFromZip('mapStargates.jsonl', function (array $row) use (&$count, &$batch, $now) {
            $stargateId = (int) ($row['_key'] ?? 0);
            if (! $stargateId) {
                return;
            }

            // Destination may be nested or flat depending on SDE version
            $destinationId = $row['destination']['stargateID']
                ?? $row['destinationStargateID']
                ?? $row['destinationID']
                ?? null;

            $batch[] = [
                'stargate_id' => $stargateId,
                'solar_system_id' => $row['solarSystemID'] ?? null,
                'destination_stargate_id' => $destinationId,
                'type_id' => $row['typeID'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= self::CHUNK_SIZE) {
                SdeStargate::upsert($batch, ['stargate_id'], [
                    'solar_system_id', 'destination_stargate_id', 'type_id', 'updated_at',
                ]);
                $count += count($batch);
                $batch = [];
            }
        });

        if (! empty($batch)) {
            SdeStargate::upsert($batch, ['stargate_id'], [
                'solar_system_id', 'destination_stargate_id', 'type_id', 'updated_at',
            ]);
            $count += count($batch);
        }

        Log::info("SDE: Imported {$count} stargates from mapStargates.jsonl");

        return $count;
    }

    /**
     * Import type materials from typeMaterials.jsonl into the type_materials table.
     *
     * JSONL format (one per line):
     * {"_key": 18, "materials": [{"materialTypeID": 34, "quantity": 175}, ...]}
     *
     * @return int Number of rows upserted.
     */
    public function importTypeMaterials(): int
    {
        $count = 0;
        $batch = [];
        $now = now();

        $this->readJsonlFromZip('typeMaterials.jsonl', function (array $row) use (&$count, &$batch, $now) {
            $typeId = (int) ($row['_key'] ?? 0);
            if (! $typeId) {
                return;
            }

            $materials = $row['materials'] ?? [];
            foreach ($materials as $mat) {
                $materialTypeId = (int) ($mat['materialTypeID'] ?? 0);
                if (! $materialTypeId) {
                    continue;
                }

                $batch[] = [
                    'type_id' => $typeId,
                    'material_type_id' => $materialTypeId,
                    'quantity' => (int) ($mat['quantity'] ?? 0),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($batch) >= self::CHUNK_SIZE) {
                    TypeMaterial::upsert($batch, ['type_id', 'material_type_id'], [
                        'quantity', 'updated_at',
                    ]);
                    $count += count($batch);
                    $batch = [];
                }
            }
        });

        if (! empty($batch)) {
            TypeMaterial::upsert($batch, ['type_id', 'material_type_id'], [
                'quantity', 'updated_at',
            ]);
            $count += count($batch);
        }

        Log::info("SDE: Imported {$count} type material rows from typeMaterials.jsonl");

        return $count;
    }

    /**
     * Import blueprint activities from blueprints.jsonl into the
     * blueprint_activities table.  Material names are resolved from the
     * eve_types table so the stored JSON matches the format expected by the
     * rest of the application.
     *
     * JSONL format (one per line):
     * {"_key": 681, "blueprintTypeID": 681, "activities": {
     *   "manufacturing": {"materials": [{"typeID": 38, "quantity": 86}],
     *     "products": [{"typeID": 165, "quantity": 1}], "time": 600}, ...}}
     *
     * @return int Number of blueprint-activity rows upserted.
     */
    public function importBlueprints(): int
    {
        // Pre-load all type names from eve_types into memory to avoid N+1 queries.
        $typeNames = EveType::pluck('name', 'type_id')->all();

        $count = 0;
        $batch = [];
        $now = now();

        $this->readJsonlFromZip('blueprints.jsonl', function (array $row) use (&$count, &$batch, $now, $typeNames) {
            $blueprintTypeId = (int) ($row['blueprintTypeID'] ?? $row['_key'] ?? 0);
            if (! $blueprintTypeId) {
                return;
            }

            $activities = $row['activities'] ?? [];

            foreach ($activities as $activityName => $activityData) {
                $activityId = self::ACTIVITY_MAP[$activityName] ?? null;
                if ($activityId === null) {
                    continue;
                }

                $materials = $this->resolveTypeList(
                    $activityData['materials'] ?? [],
                    $typeNames
                );

                $products = $this->resolveTypeList(
                    $activityData['products'] ?? [],
                    $typeNames
                );

                $batch[] = [
                    'blueprint_type_id' => $blueprintTypeId,
                    'activity_id' => $activityId,
                    'time' => (int) ($activityData['time'] ?? 0),
                    'materials' => json_encode($materials),
                    'products' => json_encode($products),
                    'skills' => json_encode($this->resolveSkillList($activityData['skills'] ?? [], $typeNames)),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($batch) >= self::CHUNK_SIZE) {
                    BlueprintActivity::upsert($batch, ['blueprint_type_id', 'activity_id'], [
                        'time', 'materials', 'products', 'skills', 'updated_at',
                    ]);
                    $count += count($batch);
                    $batch = [];
                }
            }
        });

        if (! empty($batch)) {
            BlueprintActivity::upsert($batch, ['blueprint_type_id', 'activity_id'], [
                'time', 'materials', 'products', 'skills', 'updated_at',
            ]);
            $count += count($batch);
        }

        Log::info("SDE: Imported {$count} blueprint activity rows from blueprints.jsonl");

        return $count;
    }

    /**
     * Import market groups from marketGroups.jsonl into the market_groups table.
     *
     * JSONL format (one per line):
     * {"_key": 2, "name": {"en": "Blueprint & Reactions"}, "description": {"en": "..."},
     *  "parentGroupID": null, "iconID": 22, "hasTypes": true}
     *
     * @return int Number of rows upserted.
     */
    public function importMarketGroups(): int
    {
        $count = 0;
        $batch = [];
        $now = now();

        $this->readJsonlFromZip('marketGroups.jsonl', function (array $row) use (&$count, &$batch, $now) {
            $groupId = (int) ($row['_key'] ?? 0);
            if (! $groupId) {
                return;
            }

            $batch[] = [
                'market_group_id' => $groupId,
                'name' => $this->localizedString($row['name'] ?? $row['nameID'] ?? null),
                'description' => $this->localizedString($row['description'] ?? $row['descriptionID'] ?? null, ''),
                'parent_group_id' => $row['parentGroupID'] ?? null,
                'icon_id' => $row['iconID'] ?? null,
                'has_types' => (bool) ($row['hasTypes'] ?? false),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= self::CHUNK_SIZE) {
                MarketGroup::upsert($batch, ['market_group_id'], [
                    'name', 'description', 'parent_group_id', 'icon_id', 'has_types', 'updated_at',
                ]);
                $count += count($batch);
                $batch = [];
            }
        });

        if (! empty($batch)) {
            MarketGroup::upsert($batch, ['market_group_id'], [
                'name', 'description', 'parent_group_id', 'icon_id', 'has_types', 'updated_at',
            ]);
            $count += count($batch);
        }

        Log::info("SDE: Imported {$count} market groups from marketGroups.jsonl");

        return $count;
    }

    /**
     * Import item groups from groups.jsonl into the inv_groups table.
     *
     * JSONL format (one per line):
     * {"_key": 18, "name": {"en": "Mineral"}, "categoryID": 4, "published": true, ...}
     *
     * @return int Number of rows upserted.
     */
    public function importGroups(): int
    {
        $count = 0;
        $batch = [];
        $now = now();

        $this->readJsonlFromZip('groups.jsonl', function (array $row) use (&$count, &$batch, $now) {
            $groupId = (int) ($row['_key'] ?? 0);
            if (! $groupId) {
                return;
            }

            $batch[] = [
                'group_id' => $groupId,
                'name' => $this->localizedString($row['name'] ?? null),
                'category_id' => $row['categoryID'] ?? null,
                'published' => (bool) ($row['published'] ?? false),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= self::CHUNK_SIZE) {
                InvGroup::upsert($batch, ['group_id'], [
                    'name', 'category_id', 'published', 'updated_at',
                ]);
                $count += count($batch);
                $batch = [];
            }
        });

        if (! empty($batch)) {
            InvGroup::upsert($batch, ['group_id'], [
                'name', 'category_id', 'published', 'updated_at',
            ]);
            $count += count($batch);
        }

        Log::info("SDE: Imported {$count} item groups from groups.jsonl");

        return $count;
    }

    /**
     * Import item categories from categories.jsonl into the inv_categories table.
     *
     * JSONL format (one per line):
     * {"_key": 4, "name": {"en": "Material"}, "published": true}
     *
     * @return int Number of rows upserted.
     */
    public function importCategories(): int
    {
        $count = 0;
        $batch = [];
        $now = now();

        $this->readJsonlFromZip('categories.jsonl', function (array $row) use (&$count, &$batch, $now) {
            $categoryId = (int) ($row['_key'] ?? 0);
            if (! $categoryId) {
                return;
            }

            $batch[] = [
                'category_id' => $categoryId,
                'name' => $this->localizedString($row['name'] ?? null),
                'published' => (bool) ($row['published'] ?? false),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= self::CHUNK_SIZE) {
                InvCategory::upsert($batch, ['category_id'], [
                    'name', 'published', 'updated_at',
                ]);
                $count += count($batch);
                $batch = [];
            }
        });

        if (! empty($batch)) {
            InvCategory::upsert($batch, ['category_id'], [
                'name', 'published', 'updated_at',
            ]);
            $count += count($batch);
        }

        Log::info("SDE: Imported {$count} item categories from categories.jsonl");

        return $count;
    }

    // ──────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────

    /**
     * Convert a list of {typeID, quantity, probability?} items from the SDE JSONL
     * into the {type_id, name, quantity, probability?} format used by
     * blueprint_activities.materials / products.
     *
     * The `probability` field (present on invention products) is preserved when
     * it exists in the source data.
     *
     * Names are looked up from the pre-loaded $typeNames map; items whose type
     * is not yet in the database are stored with name "Unknown".
     *
     * @param  array<int, array{typeID: int, quantity: int, probability?: float}>  $items
     * @param  array<int, string>  $typeNames  type_id → name
     * @return array<int, array{type_id: int, name: string, quantity: int, probability?: float}>
     */
    private function resolveTypeList(array $items, array $typeNames): array
    {
        $resolved = [];

        foreach ($items as $item) {
            $typeId = (int) ($item['typeID'] ?? 0);
            if (! $typeId) {
                continue;
            }

            $entry = [
                'type_id' => $typeId,
                'name' => $typeNames[$typeId] ?? 'Unknown',
                'quantity' => (int) ($item['quantity'] ?? 0),
            ];

            // Preserve probability for invention products
            if (isset($item['probability'])) {
                $entry['probability'] = (float) $item['probability'];
            }

            $resolved[] = $entry;
        }

        return $resolved;
    }

    /**
     * Convert a list of {typeID, level} skill requirements from the SDE JSONL
     * into the {type_id, name, level} format for blueprint_activities.skills.
     *
     * @param  array<int, array{typeID: int, level: int}>  $skills
     * @param  array<int, string>  $typeNames  type_id → name
     * @return array<int, array{type_id: int, name: string, level: int}>
     */
    private function resolveSkillList(array $skills, array $typeNames): array
    {
        $resolved = [];

        foreach ($skills as $skill) {
            $typeId = (int) ($skill['typeID'] ?? 0);
            if (! $typeId) {
                continue;
            }

            $resolved[] = [
                'type_id' => $typeId,
                'name' => $typeNames[$typeId] ?? 'Unknown',
                'level' => (int) ($skill['level'] ?? 0),
            ];
        }

        return $resolved;
    }
}
