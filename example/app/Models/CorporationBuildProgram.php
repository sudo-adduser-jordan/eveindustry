<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorporationBuildProgram extends Model
{
    protected $fillable = [
        'corporation_id',
        'created_by_user_id',
        'name',
        'program_type',
        't2_type_id',
        't2_type_name',
        'steps',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'corporation_id' => 'integer',
            'created_by_user_id' => 'integer',
            't2_type_id' => 'integer',
            'steps' => 'array',
            'settings' => 'array',
        ];
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
