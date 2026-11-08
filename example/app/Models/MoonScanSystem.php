<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MoonScanSystem extends Model
{
    protected $table = 'moon_scan_systems';

    protected $fillable = [
        'system_name',
        'corporation_id',
        'region_id',
        'pricing_mode',
        'last_scanned_at',
    ];

    protected function casts(): array
    {
        return [
            'region_id' => 'integer',
            'corporation_id' => 'integer',
            'last_scanned_at' => 'datetime',
        ];
    }

    public function moons(): HasMany
    {
        return $this->hasMany(MoonScanResult::class, 'system_id');
    }
}
