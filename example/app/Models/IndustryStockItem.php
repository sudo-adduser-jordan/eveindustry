<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IndustryStockItem extends Model
{
    protected $fillable = [
        'user_id',
        'type_id',
        'type_name',
        'desired_quantity',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'type_id' => 'integer',
            'desired_quantity' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
