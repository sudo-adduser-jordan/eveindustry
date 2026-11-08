<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use LogicException;

class PublicContract extends Model
{
    protected $table = 'public_contract_snapshot_contracts';

    protected $primaryKey = 'contract_id';

    public $incrementing = false;

    protected static function booted(): void
    {
        static::addGlobalScope('active_generation', function (Builder $query): void {
            $generationId = static::activeGenerationId();
            $generationId === null
                ? $query->whereRaw('1 = 0')
                : $query->where('generation_id', $generationId);
        });

        static::creating(function (self $contract): void {
            $contract->generation_id ??= static::activeGenerationId();
            if ($contract->generation_id === null) {
                throw new LogicException('Cannot write a public contract without an active generation.');
            }
        });
    }

    public static function activeGenerationId(): ?int
    {
        $id = DB::table('public_contract_snapshot_state')->where('id', 1)->value('active_generation_id');

        return $id === null ? null : (int) $id;
    }

    protected $fillable = [
        'contract_id',
        'region_id',
        'type',
        'title',
        'for_corporation',
        'issuer_corporation_id',
        'issuer_id',
        'date_issued',
        'date_expired',
        'days_to_complete',
        'start_location_id',
        'end_location_id',
        'station_id',
        'system_id',
        'constellation_id',
        'price',
        'buyout',
        'reward',
        'collateral',
        'volume',
        'http_last_modified',
    ];

    protected function casts(): array
    {
        return [
            'contract_id' => 'integer',
            'region_id' => 'integer',
            'for_corporation' => 'boolean',
            'issuer_corporation_id' => 'integer',
            'issuer_id' => 'integer',
            'date_issued' => 'datetime',
            'date_expired' => 'datetime',
            'days_to_complete' => 'integer',
            'start_location_id' => 'integer',
            'end_location_id' => 'integer',
            'station_id' => 'integer',
            'system_id' => 'integer',
            'constellation_id' => 'integer',
            'price' => 'float',
            'buyout' => 'float',
            'reward' => 'float',
            'collateral' => 'float',
            'volume' => 'float',
            'http_last_modified' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        $items = $this->hasMany(PublicContractItem::class, 'contract_id', 'contract_id');
        if ($this->generation_id !== null) {
            $items->where('generation_id', $this->generation_id);
        }

        return $items;
    }
}
