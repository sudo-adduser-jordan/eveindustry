<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EveType extends Model
{
    /** Meta group IDs used in the EVE SDE. */
    public const META_GROUP_TECH_I = 1;

    public const META_GROUP_TECH_II = 2;

    public const META_GROUP_STORYLINE = 3;

    public const META_GROUP_FACTION = 4;

    public const META_GROUP_OFFICER = 5;

    public const META_GROUP_DEADSPACE = 6;

    public const META_GROUP_TECH_III = 14;

    public const META_GROUP_ABYSSAL = 15;

    protected $table = 'eve_types';

    protected $primaryKey = 'type_id';

    public $incrementing = false;

    protected $fillable = [
        'type_id',
        'name',
        'description',
        'group_id',
        'market_group_id',
        'volume',
        'packaged_volume',
        'mass',
        'capacity',
        'portion_size',
        'icon_id',
        'graphic_id',
        'meta_group_id',
        'published',
    ];

    protected function casts(): array
    {
        return [
            'type_id' => 'integer',
            'group_id' => 'integer',
            'market_group_id' => 'integer',
            'volume' => 'float',
            'packaged_volume' => 'float',
            'mass' => 'float',
            'capacity' => 'float',
            'portion_size' => 'integer',
            'icon_id' => 'integer',
            'graphic_id' => 'integer',
            'meta_group_id' => 'integer',
            'published' => 'boolean',
        ];
    }

    public function marketGroup(): BelongsTo
    {
        return $this->belongsTo(MarketGroup::class, 'market_group_id', 'market_group_id');
    }

    public function invGroup(): BelongsTo
    {
        return $this->belongsTo(InvGroup::class, 'group_id', 'group_id');
    }
}
