<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvCategory extends Model
{
    protected $table = 'inv_categories';

    protected $primaryKey = 'category_id';

    public $incrementing = false;

    protected $fillable = [
        'category_id',
        'name',
        'published',
    ];

    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'published' => 'boolean',
        ];
    }

    public function groups(): HasMany
    {
        return $this->hasMany(InvGroup::class, 'category_id', 'category_id');
    }
}
