<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SdeSolarSystem extends Model
{
    protected $table = 'sde_solar_systems';

    protected $primaryKey = 'solar_system_id';

    public $incrementing = false;

    protected $fillable = [
        'solar_system_id',
        'solar_system_name',
        'constellation_id',
        'region_id',
        'security',
        'security_class',
    ];

    protected function casts(): array
    {
        return [
            'solar_system_id' => 'integer',
            'constellation_id' => 'integer',
            'region_id' => 'integer',
            'security' => 'float',
        ];
    }

    /**
     * The name to store for a system: the one the user typed, else the SDE's,
     * else a placeholder naming the id.
     */
    public static function displayName(int $solarSystemId, ?string $provided = null): string
    {
        if ($provided && trim($provided) !== '') {
            return trim($provided);
        }

        return static::find($solarSystemId)?->solar_system_name ?? ('System #'.$solarSystemId);
    }
}
