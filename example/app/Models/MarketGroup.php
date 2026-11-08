<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketGroup extends Model
{
    protected $table = 'market_groups';

    protected $primaryKey = 'market_group_id';

    public $incrementing = false;

    protected $fillable = [
        'market_group_id',
        'name',
        'description',
        'parent_group_id',
        'icon_id',
        'has_types',
    ];

    protected function casts(): array
    {
        return [
            'market_group_id' => 'integer',
            'parent_group_id' => 'integer',
            'icon_id' => 'integer',
            'has_types' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(MarketGroup::class, 'parent_group_id', 'market_group_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(MarketGroup::class, 'parent_group_id', 'market_group_id');
    }

    public function types(): HasMany
    {
        return $this->hasMany(EveType::class, 'market_group_id', 'market_group_id');
    }
}
