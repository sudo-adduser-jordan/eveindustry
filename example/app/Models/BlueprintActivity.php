<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BlueprintActivity extends Model
{
    protected $table = 'blueprint_activities';

    protected $fillable = [
        'blueprint_type_id',
        'activity_id',
        'time',
        'materials',
        'products',
        'skills',
    ];

    protected function casts(): array
    {
        return [
            'blueprint_type_id' => 'integer',
            'activity_id' => 'integer',
            'time' => 'integer',
            'materials' => 'array',
            'products' => 'array',
            'skills' => 'array',
        ];
    }

    public const ACTIVITY_MANUFACTURING = 1;
    public const ACTIVITY_COPYING = 5;
    public const ACTIVITY_INVENTION = 8;
    public const ACTIVITY_REACTION = 11;

    /**
     * Scope to find activities that produce a given type_id.
     *
     * Uses a portable JSON query that works with both MySQL and SQLite,
     * since whereJsonContains with nested objects is unreliable on SQLite.
     */
    public function scopeWhereProducesType($query, int $typeId): mixed
    {
        $driver = $query->getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return $query->whereRaw(
                "EXISTS (SELECT 1 FROM json_each(products) WHERE json_extract(json_each.value, '$.type_id') = ?)",
                [$typeId]
            );
        }

        return $query->whereJsonContains('products', [['type_id' => $typeId]]);
    }
}
