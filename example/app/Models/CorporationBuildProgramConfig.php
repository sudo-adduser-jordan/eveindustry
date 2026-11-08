<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorporationBuildProgramConfig extends Model
{
    protected $fillable = [
        'corporation_id',
        'corporation_name',
        'character_id',
        'enabled_by_user_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'corporation_id' => 'integer',
            'character_id' => 'integer',
            'enabled_by_user_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'character_id', 'character_id');
    }

    public function enabledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enabled_by_user_id');
    }

    /**
     * Find an active config for a given corporation.
     */
    public static function findActiveForCorporation(int $corporationId): ?self
    {
        return static::where('corporation_id', $corporationId)
            ->where('is_active', true)
            ->first();
    }
}
