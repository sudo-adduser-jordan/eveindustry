<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvGroup extends Model
{
    protected $table = 'inv_groups';

    protected $primaryKey = 'group_id';

    public $incrementing = false;

    protected $fillable = [
        'group_id',
        'name',
        'category_id',
        'published',
    ];

    protected function casts(): array
    {
        return [
            'group_id' => 'integer',
            'category_id' => 'integer',
            'published' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(InvCategory::class, 'category_id', 'category_id');
    }

    public function types(): HasMany
    {
        return $this->hasMany(EveType::class, 'group_id', 'group_id');
    }
}
