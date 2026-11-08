<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TypeMaterial extends Model
{
    protected $table = 'type_materials';

    protected $fillable = [
        'type_id',
        'material_type_id',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'type_id' => 'integer',
            'material_type_id' => 'integer',
            'quantity' => 'integer',
        ];
    }
}
