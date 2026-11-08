<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MoonScanResult extends Model
{
    protected $table = 'moon_scan_results';

    protected $fillable = [
        'system_id',
        'moon_name',
        'planet_name',
        'grade',
        'ores',
        'total_value',
        'scanned_at',
        'occupied',
        'occupying_corporation_id',
        'occupying_corporation_name',
    ];

    protected function casts(): array
    {
        return [
            'ores' => 'array',
            'total_value' => 'integer',
            'system_id' => 'integer',
            'scanned_at' => 'datetime',
            'occupied' => 'boolean',
            'occupying_corporation_id' => 'integer',
        ];
    }

    public function system(): BelongsTo
    {
        return $this->belongsTo(MoonScanSystem::class, 'system_id');
    }
}
