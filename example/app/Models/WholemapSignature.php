<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WholemapSignature extends Model
{
    protected $table = 'wholemap_signatures';

    protected $fillable = [
        'user_id',
        'signature_id',
        'system_id',
        'system_name',
        'type',
        'name',
        'bookmark',
        'description',
        'locked',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'system_id' => 'integer',
            'locked'    => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wormholeConnection(): HasOne
    {
        return $this->hasOne(WormholeConnection::class, 'signature_id');
    }
}
