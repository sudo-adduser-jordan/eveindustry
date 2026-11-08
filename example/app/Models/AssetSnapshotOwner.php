<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetSnapshotOwner extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'owner_id' => 'integer', 'generation' => 'integer', 'active_snapshot_id' => 'integer',
            'last_checked_at' => 'datetime', 'last_succeeded_at' => 'datetime', 'next_due_at' => 'datetime',
        ];
    }
}
