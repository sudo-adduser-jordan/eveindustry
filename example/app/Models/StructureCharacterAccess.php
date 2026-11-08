<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StructureCharacterAccess extends Model
{
    protected $table = 'structure_character_access';

    protected $primaryKey = null;

    public $incrementing = false;

    protected $fillable = [
        'structure_id',
        'character_id',
        'access_denied_at',
    ];

    protected function casts(): array
    {
        return [
            'structure_id'    => 'integer',
            'character_id'    => 'integer',
            'access_denied_at' => 'datetime',
        ];
    }
}
