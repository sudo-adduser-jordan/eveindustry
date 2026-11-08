<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WormholeConnection extends Model
{
    protected $fillable = [
        'user_id',
        'signature_id',
        'from_system_id',
        'from_system_name',
        'to_system_id',
        'to_system_name',
        'wormhole_type',
        'size',
        'life',
        'mass',
    ];

    protected function casts(): array
    {
        return [
            'from_system_id' => 'integer',
            'to_system_id'   => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function signature(): BelongsTo
    {
        return $this->belongsTo(WholemapSignature::class, 'signature_id');
    }

    public function jumpLogs(): HasMany
    {
        return $this->hasMany(WormholeJumpLog::class, 'connection_id');
    }
}
