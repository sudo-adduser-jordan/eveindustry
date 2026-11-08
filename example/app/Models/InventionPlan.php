<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventionPlan extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        't2_type_id',
        't2_type_name',
        'decryptor_key',
        't1_me',
        't1_te',
        'encryption_skill',
        'science1_skill',
        'science2_skill',
        'invention_runs',
        'structure_type',
        'rig_me',
        'rig_te',
        'system_security',
        'facility_tax',
        'solar_system_id',
        'station_id',
        'price_region_id',
    ];

    protected function casts(): array
    {
        return [
            't2_type_id' => 'integer',
            't1_me' => 'integer',
            't1_te' => 'integer',
            'encryption_skill' => 'integer',
            'science1_skill' => 'integer',
            'science2_skill' => 'integer',
            'invention_runs' => 'integer',
            'facility_tax' => 'decimal:2',
            'solar_system_id' => 'integer',
            'station_id' => 'integer',
            'price_region_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
