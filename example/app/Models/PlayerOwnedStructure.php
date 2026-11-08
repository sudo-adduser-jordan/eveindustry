<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayerOwnedStructure extends Model
{
    protected $table = 'player_owned_structures';

    protected $primaryKey = 'structure_id';

    public $incrementing = false;

    protected $fillable = [
        'structure_id',
        'name',
        'owner_id',
        'solar_system_id',
        'type_id',
        'position_x',
        'position_y',
        'position_z',
    ];

    protected function casts(): array
    {
        return [
            'owner_id' => 'integer',
            'solar_system_id' => 'integer',
            'type_id' => 'integer',
            'position_x' => 'float',
            'position_y' => 'float',
            'position_z' => 'float',
        ];
    }
}
