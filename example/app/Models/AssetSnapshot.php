<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetSnapshot extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'generation' => 'integer', 'item_count' => 'integer',
            'enrichment_pending' => 'boolean',
            'source_updated_at' => 'datetime', 'source_expires_at' => 'datetime', 'published_at' => 'datetime',
        ];
    }
}
