<?php

namespace App\Services;

use App\Models\Character;
use App\Models\EveType;
use Illuminate\Support\Facades\Log;

class AbyssalService
{
    /**
     * Dogma attribute IDs that are numeric display labels (not interesting for comparison).
     * These are internal counters and flags that don't represent module performance.
     */
    protected const SKIP_ATTRIBUTE_IDS = [
        // Internal/meta attributes not meaningful for roll comparison
        422,  // requiredSkill1 (skill type_id reference)
        277,  // requiredSkill1Level
        182,  // requiredSkill2
        278,  // requiredSkill2Level
        183,  // requiredSkill3
        279,  // requiredSkill3Level
        633,  // implantness
        1795, // mutatableAttribute
    ];

    /**
     * Dogma attribute names whose values are stored in milliseconds but may be
     * tagged with an incorrect unit_id in the ESI (e.g. 101/Inverse Absolute
     * Percent instead of 115/Milliseconds).  We force unit_id = 115 for these.
     */
    protected const MILLISECOND_ATTRIBUTE_NAMES = [
        'duration',   // Activation time / duration
        'speed',      // Rate of fire
        'reloadTime', // Reload time
    ];

    /**
     * Dogma attribute names whose values are in cubic metres (m³) but may be
     * tagged with an incorrect unit_id in the ESI (e.g. 9/Teraflops).
     * We force unit_id = 120 for these so they display as m³, not tf.
     */
    protected const CUBIC_METER_ATTRIBUTE_NAMES = [
        'miningAmount', // Volume mined per cycle
    ];

    /** Minimum absolute difference required to consider an attribute changed vs. base. */
    protected const ATTRIBUTE_CHANGE_EPSILON = 0.0001;

    /**
     * Synthetic unit_id used for the computed "Effective Mining Speed" attribute (m³/s).
     * Not a real ESI unit — only used internally for formatting.
     */
    public const UNIT_EFFECTIVE_M3S = 9001;

    public function __construct(
        protected EsiService $esi,
        protected AssetService $assetService
    ) {}

    /**
     * Get all abyssal/mutated items for a user with dogma attribute comparison.
     * Items are grouped by their source (base) type and sorted by quality score.
     *
     * Returns:
     * [
     *   [
     *     'source_type_id'   => int,
     *     'source_type_name' => string,
     *     'items' => [
     *       [
     *         'item_id'        => int,
     *         'type_id'        => int,
     *         'type_name'      => string,
     *         'character_name' => string,
     *         'character_id'   => int,
     *         'location_name'  => string,
     *         'mutator_type_id' => int|null,
     *         'score'          => float,
     *         'attributes'     => [...],
     *       ],
     *       ...
     *     ],
     *   ],
     *   ...
     * ]
     */
    public function getAbyssalItemsForUser(int $userId): array
    {
        $assets = $this->assetService->getAssetsForUser($userId);

        // Batch-load all type IDs that appear in the asset list.
        // Filter for abyssal items (meta_group_id = EveType::META_GROUP_ABYSSAL = 15).
        $typeIds = array_unique(array_column($assets, 'type_id'));
        $abyssalTypeIds = [];

        if (! empty($typeIds)) {
            try {
                EveType::whereIn('type_id', $typeIds)
                    ->where('meta_group_id', EveType::META_GROUP_ABYSSAL)
                    ->pluck('type_id')
                    ->each(function ($id) use (&$abyssalTypeIds) {
                        $abyssalTypeIds[$id] = true;
                    });
            } catch (\Exception $e) {
                Log::debug('AbyssalService: Could not bulk-load abyssal type IDs.', ['error' => $e->getMessage()]);
            }
        }

        if (empty($abyssalTypeIds)) {
            return [];
        }

        // Collect all abyssal assets
        $abyssalAssets = array_filter($assets, fn ($a) => isset($abyssalTypeIds[$a['type_id'] ?? 0]));

        if (empty($abyssalAssets)) {
            return [];
        }

        // Build a map of character_id => Character for quick lookup
        $characters = Character::where('user_id', $userId)
            ->where(fn ($q) => $q->whereJsonContains('scopes', 'esi-assets.read_assets.v1'))
            ->get();

        $charMap = $characters->keyBy('character_id')->all();

        if (empty($charMap)) {
            return [];
        }

        // Build a map of item_id => asset for container name lookup
        $assetById = [];
        foreach ($assets as $a) {
            if (! empty($a['item_id'])) {
                $assetById[$a['item_id']] = $a;
            }
        }

        $groups = [];

        foreach ($abyssalAssets as $asset) {
            $itemId = $asset['item_id'] ?? 0;
            $typeId = $asset['type_id'] ?? 0;
            $charId = $asset['character_id'] ?? 0;

            if (! $itemId || ! $typeId) {
                continue;
            }

            // Use the owning character; fall back to any scoped character
            $character = $charMap[$charId] ?? reset($charMap);
            if (! $character) {
                continue;
            }

            // Fetch per-item dynamic attributes (authenticated, cached 1 hour)
            $dynamicData = $this->esi->getDynamicItem($typeId, $itemId, $character);
            if (! $dynamicData) {
                continue;
            }

            $sourceTypeId = $dynamicData['source_type_id'] ?? null;
            if (! $sourceTypeId) {
                continue;
            }

            // Fetch base type dogma attributes (public endpoint, cached 30 days)
            $baseData = $this->esi->getDogmaTypeAttributes((int) $sourceTypeId);
            $baseAttributes = [];
            if ($baseData && isset($baseData['dogma_attributes'])) {
                foreach ($baseData['dogma_attributes'] as $attr) {
                    $baseAttributes[(int) $attr['attribute_id']] = (float) $attr['value'];
                }
            }

            // Index dynamic attributes
            $dynamicAttributes = [];
            if (isset($dynamicData['dogma_attributes'])) {
                foreach ($dynamicData['dogma_attributes'] as $attr) {
                    $dynamicAttributes[(int) $attr['attribute_id']] = (float) $attr['value'];
                }
            }

            // Build attribute comparison: only show attributes that changed vs. base
            $attributeComparison = [];
            $score = 0.0;

            foreach ($dynamicAttributes as $attrId => $value) {
                if (in_array($attrId, self::SKIP_ATTRIBUTE_IDS, true)) {
                    continue;
                }

                $baseValue = $baseAttributes[$attrId] ?? null;
                if ($baseValue === null) {
                    continue;
                }

                // Skip attributes that haven't changed
                if (abs($value - $baseValue) < self::ATTRIBUTE_CHANGE_EPSILON) {
                    continue;
                }

                // Fetch attribute metadata (name, high_is_good) — public, cached 30 days
                $attrMeta = $this->esi->getDogmaAttribute($attrId);
                $highIsGood = $attrMeta['high_is_good'] ?? false;
                $attrName = $attrMeta['display_name'] ?? $attrMeta['name'] ?? "Attribute #{$attrId}";
                $unitId = $attrMeta['unit_id'] ?? null;
                $iconId = $attrMeta['icon_id'] ?? null;

                // Some time/duration attributes carry an incorrect unit_id in the
                // ESI (e.g. 101 "Inverse Absolute Percent") while their values are
                // actually stored in milliseconds.  Force milliseconds formatting.
                $rawName = $attrMeta['name'] ?? '';
                if (in_array($rawName, self::MILLISECOND_ATTRIBUTE_NAMES, true)) {
                    $unitId = 115;
                }
                // Mining amount is stored in m³ but ESI may report unit_id 9 (tf).
                if (in_array($rawName, self::CUBIC_METER_ATTRIBUTE_NAMES, true)) {
                    $unitId = 120;
                }

                // Percentage change relative to base
                $pctChange = $baseValue != 0.0
                    ? (($value - $baseValue) / abs($baseValue)) * 100.0
                    : null;

                // A change is "good" if it moves in the high_is_good direction
                $isGood = ($highIsGood)
                    ? $value >= $baseValue
                    : $value <= $baseValue;

                // Contribute to overall quality score
                if ($pctChange !== null) {
                    $score += $isGood ? abs($pctChange) : -abs($pctChange);
                }

                $attributeComparison[$attrId] = [
                    'attribute_id' => $attrId,
                    'name' => $attrName,
                    'unit_id' => $unitId,
                    'icon_id' => $iconId,
                    'value' => $value,
                    'base_value' => $baseValue,
                    'pct_change' => $pctChange,
                    'high_is_good' => $highIsGood,
                    'is_good' => $isGood,
                ];
            }

            // Sort attributes alphabetically by display name for a stable, consistent order
            uasort($attributeComparison, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

            // Compute and append Effective Mining Speed (m³/s) when both mining amount
            // and activation duration attributes are present on this item.
            // The search covers both changed attributes (in $attributeComparison) and
            // unchanged ones (still in $dynamicAttributes / $baseAttributes) so that
            // effective rate is shown whenever at least one of the two has changed.
            $miningAttr = null;
            $durationAttr = null;
            foreach ($attributeComparison as $attr) {
                if ($attr['unit_id'] === 120 && stripos($attr['name'], 'mining') !== false) {
                    $miningAttr = $attr;
                } elseif ($attr['unit_id'] === 115 && $durationAttr === null) {
                    $durationAttr = $attr;
                }
            }

            // If one of the pair was not changed (and therefore not in $attributeComparison),
            // scan the full dynamic attribute set to find it.
            if ($miningAttr !== null && $durationAttr === null) {
                foreach ($dynamicAttributes as $scanId => $scanValue) {
                    if (isset($attributeComparison[$scanId])) {
                        continue; // already examined above
                    }
                    $scanMeta = $this->esi->getDogmaAttribute($scanId);
                    $scanRaw = $scanMeta['name'] ?? '';
                    if (in_array($scanRaw, self::MILLISECOND_ATTRIBUTE_NAMES, true) || ($scanMeta['unit_id'] ?? null) === 115) {
                        $scanBase = $baseAttributes[$scanId] ?? null;
                        if ($scanBase !== null && $scanBase > 0) {
                            $durationAttr = ['value' => $scanValue, 'base_value' => (float) $scanBase];
                            break;
                        }
                    }
                }
            }

            if ($durationAttr !== null && $miningAttr === null) {
                foreach ($dynamicAttributes as $scanId => $scanValue) {
                    if (isset($attributeComparison[$scanId])) {
                        continue;
                    }
                    $scanMeta = $this->esi->getDogmaAttribute($scanId);
                    $scanRaw = $scanMeta['name'] ?? '';
                    $scanUnitId = $scanMeta['unit_id'] ?? null;
                    if ($scanUnitId === 120 || in_array($scanRaw, self::CUBIC_METER_ATTRIBUTE_NAMES, true)) {
                        $scanBase = $baseAttributes[$scanId] ?? null;
                        if ($scanBase !== null) {
                            $miningAttr = ['value' => $scanValue, 'base_value' => (float) $scanBase];
                            break;
                        }
                    }
                }
            }

            if ($miningAttr !== null && $durationAttr !== null
                && $durationAttr['value'] > 0 && $durationAttr['base_value'] > 0
            ) {
                $effValue = $miningAttr['value'] / ($durationAttr['value'] / 1000.0);
                $effBase = $miningAttr['base_value'] / ($durationAttr['base_value'] / 1000.0);
                $effPct = $effBase != 0.0
                    ? (($effValue - $effBase) / abs($effBase)) * 100.0
                    : null;
                $attributeComparison['_effective_m3s'] = [
                    'attribute_id' => '_effective_m3s',
                    'name' => 'Effective Mining Speed',
                    'unit_id' => self::UNIT_EFFECTIVE_M3S,
                    'icon_id' => null,
                    'value' => $effValue,
                    'base_value' => $effBase,
                    'pct_change' => $effPct,
                    'high_is_good' => true,
                    'is_good' => $effValue >= $effBase,
                ];
            }

            // Determine the container custom name (if the item sits inside another item)
            $parentItemId = $asset['parent_item_id'] ?? null;
            $containerName = null;
            if ($parentItemId && isset($assetById[$parentItemId])) {
                $parentAsset = $assetById[$parentItemId];
                // Prefer the player-given name; fall back to the generic type name
                $containerName = $parentAsset['name'] ?? $parentAsset['type_name'] ?? null;
            }

            $sourceTypeName = $this->resolveTypeName((int) $sourceTypeId);

            if (! isset($groups[$sourceTypeId])) {
                $groups[$sourceTypeId] = [
                    'source_type_id' => (int) $sourceTypeId,
                    'source_type_name' => $sourceTypeName,
                    'items' => [],
                ];
            }

            $groups[$sourceTypeId]['items'][] = [
                'item_id' => $itemId,
                'type_id' => $typeId,
                'type_name' => $asset['type_name'] ?? 'Unknown',
                'character_name' => $asset['character_name'] ?? '',
                'character_id' => $charId,
                'location_name' => $asset['location_name'] ?? 'Unknown',
                'container_name' => $containerName,
                'mutator_type_id' => isset($dynamicData['mutator_type_id']) ? (int) $dynamicData['mutator_type_id'] : null,
                'score' => $score,
                'attributes' => array_values($attributeComparison),
            ];
        }

        // Sort items within each group by score descending (best first)
        foreach ($groups as &$group) {
            usort($group['items'], fn ($a, $b) => $b['score'] <=> $a['score']);
        }
        unset($group);

        // Sort groups alphabetically by source type name
        $result = array_values($groups);
        usort($result, fn ($a, $b) => strcasecmp($a['source_type_name'], $b['source_type_name']));

        return $result;
    }

    /**
     * Format a dogma attribute value for display based on its unit_id.
     *
     * @param  float  $value  The attribute value
     * @param  int|null  $unitId  The ESI dogma unit_id
     * @return string Human-readable formatted value
     */
    public static function formatAttributeValue(float $value, ?int $unitId): string
    {
        return match ($unitId) {
            // Percentages (stored as 0–1 or as raw percent depending on unit)
            // unit 104 = Inverse Relative Percent, 105 = Absolute Percent, 106 = Relative Percent
            104, 105, 106 => number_format($value * 100, 2).'%',
            // unit 101 = Inverse Absolute Percent (e.g. resistances shown as "70%")
            101 => number_format((1 - $value) * 100, 2).'%',
            // unit 108 = Boolean
            108 => $value ? 'true' : 'false',
            // unit 109 = Multiplier
            109, 118 => number_format($value, 4).'x',
            // unit 115 = Milliseconds
            115 => number_format($value / 1000, 2).'s',
            // unit 119 = Seconds
            119 => number_format($value, 2).'s',
            // unit 120 = m³ (e.g. mining amount)
            120 => number_format($value, 2).' m³',
            // unit 121 = m/s (velocity)
            121 => number_format($value, 2).' m/s',
            // unit 9  = Teraflops (CPU usage)
            9 => number_format($value, 2).' tf',
            // unit 11 = Megawatts (Powergrid); unit 30 also maps to MW in some ESI attribute metadata
            11, 30 => number_format($value, 2).' MW',
            // unit 113 = Gigajoules (Activation Cost / capacitor)
            113 => number_format($value, 2).' GJ',
            // Effective Mining Speed (computed synthetic attribute)
            self::UNIT_EFFECTIVE_M3S => number_format($value, 2).' m³/s',
            // Meters (range, fallback for 117)
            117 => $value >= 1000
                ? number_format($value / 1000, 2).' km'
                : number_format($value, 2).' m',
            default => number_format($value, 2),
        };
    }

    /**
     * Format the change (diff) of a dogma attribute value for display.
     *
     * Shows the absolute difference between rolled and base values with a +/- sign
     * and the appropriate unit suffix.
     *
     * @param  float  $value  The rolled attribute value
     * @param  float  $baseValue  The base attribute value
     * @param  int|null  $unitId  The ESI dogma unit_id
     * @return string Human-readable formatted change (e.g. "+2.15s", "-6.97GJ")
     */
    public static function formatAttributeChange(float $value, float $baseValue, ?int $unitId): string
    {
        $diff = $value - $baseValue;
        $sign = $diff >= 0 ? '+' : '';

        return match ($unitId) {
            // Percentages (stored as 0–1 ratio)
            104, 105, 106 => $sign.number_format($diff * 100, 2).'%',
            // Inverse Absolute Percent: displayed as (1-v)*100, so change = (base-value)*100
            101 => (function () use ($value, $baseValue) {
                $displayDiff = ($baseValue - $value) * 100;

                return ($displayDiff >= 0 ? '+' : '').number_format($displayDiff, 2).'%';
            })(),
            // Boolean — no meaningful change display
            108 => '',
            // Multiplier
            109, 118 => $sign.number_format($diff, 4).'x',
            // Milliseconds → seconds
            115 => $sign.number_format($diff / 1000, 2).'s',
            // Seconds
            119 => $sign.number_format($diff, 2).'s',
            // m³
            120 => $sign.number_format($diff, 2).' m³',
            // m/s
            121 => $sign.number_format($diff, 2).' m/s',
            // Teraflops (CPU)
            9 => $sign.number_format($diff, 2).' tf',
            // Megawatts (Powergrid); unit 11 is the primary ESI unit, unit 30 also appears in some attribute metadata
            11, 30 => $sign.number_format($diff, 2).' MW',
            // Gigajoules (Activation Cost)
            113 => $sign.number_format($diff, 2).' GJ',
            // Effective Mining Speed (computed synthetic attribute)
            self::UNIT_EFFECTIVE_M3S => $sign.number_format($diff, 2).' m³/s',
            // Meters / km
            117 => abs($diff) >= 1000
                ? $sign.number_format($diff / 1000, 2).' km'
                : $sign.number_format($diff, 2).' m',
            default => $sign.number_format($diff, 2),
        };
    }

    /**
     * Resolve a type_id to its name from local SDE or ESI.
     */
    protected function resolveTypeName(int $typeId): string
    {
        try {
            $type = EveType::find($typeId);
            if ($type) {
                return $type->name;
            }
        } catch (\Exception $e) {
            // ignore
        }

        $info = $this->esi->getTypeInfo($typeId);

        return $info['name'] ?? "Type #{$typeId}";
    }
}
