<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SdeStation extends Model
{
    protected $table = 'sde_stations';

    protected $primaryKey = 'station_id';

    public $incrementing = false;

    protected $fillable = [
        'station_id',
        'station_name',
        'solar_system_id',
        'constellation_id',
        'region_id',
        'station_type_id',
        'corporation_id',
        'reprocessing_efficiency',
        'security',
    ];

    protected function casts(): array
    {
        return [
            'station_id'              => 'integer',
            'solar_system_id'         => 'integer',
            'constellation_id'        => 'integer',
            'region_id'               => 'integer',
            'station_type_id'         => 'integer',
            'corporation_id'          => 'integer',
            'reprocessing_efficiency' => 'float',
            'security'                => 'float',
        ];
    }
}
