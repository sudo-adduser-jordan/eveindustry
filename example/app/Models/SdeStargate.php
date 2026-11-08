<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SdeStargate extends Model
{
    protected $table = 'sde_stargates';

    protected $primaryKey = 'stargate_id';

    public $incrementing = false;

    protected $fillable = [
        'stargate_id',
        'solar_system_id',
        'destination_stargate_id',
        'type_id',
    ];

    protected function casts(): array
    {
        return [
            'stargate_id'             => 'integer',
            'solar_system_id'         => 'integer',
            'destination_stargate_id' => 'integer',
            'type_id'                 => 'integer',
        ];
    }
}
