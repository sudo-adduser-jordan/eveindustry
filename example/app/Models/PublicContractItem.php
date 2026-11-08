<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicContractItem extends Model
{
    protected $table = 'public_contract_snapshot_items';

    protected static function booted(): void
    {
        static::addGlobalScope('active_generation', function (Builder $query): void {
            $generationId = PublicContract::activeGenerationId();
            $generationId === null
                ? $query->whereRaw('1 = 0')
                : $query->where('generation_id', $generationId);
        });

        static::creating(function (self $item): void {
            $item->generation_id ??= PublicContract::activeGenerationId();
            if ($item->generation_id === null) {
                throw new \LogicException('Cannot write a public contract item without an active generation.');
            }
        });
    }

    protected $fillable = [
        'contract_id',
        'type_id',
        'quantity',
        'is_included',
        'is_blueprint_copy',
        'material_efficiency',
        'time_efficiency',
        'runs',
        'record_id',
        'item_id',
        'http_last_modified',
    ];

    protected function casts(): array
    {
        return [
            'contract_id' => 'integer',
            'type_id' => 'integer',
            'quantity' => 'integer',
            'is_included' => 'boolean',
            'is_blueprint_copy' => 'boolean',
            'material_efficiency' => 'integer',
            'time_efficiency' => 'integer',
            'runs' => 'integer',
            'record_id' => 'integer',
            'item_id' => 'integer',
            'http_last_modified' => 'datetime',
        ];
    }

    public function contract(): BelongsTo
    {
        $contract = $this->belongsTo(PublicContract::class, 'contract_id', 'contract_id');
        if ($this->generation_id !== null) {
            $contract->where('generation_id', $this->generation_id);
        }

        return $contract;
    }
}
