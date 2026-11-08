<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WormholeJumpLog extends Model
{
    protected $table = 'wormhole_jump_logs';

    protected $fillable = [
        'connection_id',
        'user_id',
        'character_id',
        'character_name',
        'ship_type_id',
        'ship_type_name',
        'ship_mass',
        'jumped_at',
    ];

    protected function casts(): array
    {
        return [
            'connection_id' => 'integer',
            'character_id'  => 'integer',
            'ship_type_id'  => 'integer',
            'ship_mass'     => 'float',
            'jumped_at'     => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(WormholeConnection::class, 'connection_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
