<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EssPayoutEvent extends Model
{
    protected $table = 'ess_payout_events';

    protected $fillable = [
        'ess_tracked_system_id',
        'occurred_at',
        'amount_isk',
        'recorded_by_user_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'ess_tracked_system_id' => 'integer',
            'occurred_at' => 'datetime',
            'amount_isk' => 'decimal:2',
            'recorded_by_user_id' => 'integer',
        ];
    }

    public function trackedSystem(): BelongsTo
    {
        return $this->belongsTo(EssTrackedSystem::class, 'ess_tracked_system_id');
    }
}
