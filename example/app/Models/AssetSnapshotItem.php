<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetSnapshotItem extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['item_id' => 'integer', 'payload' => 'array', 'raw_payload' => 'array'];
    }
}
