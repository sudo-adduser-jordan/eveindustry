<?php

namespace App\Models;

use App\Services\Demo\DemoIds;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Character extends Model
{
    protected $primaryKey = 'character_id';

    public $incrementing = false;

    protected $fillable = [
        'character_id',
        'user_id',
        'name',
        'corporation_id',
        'corporation_name',
        'alliance_id',
        'alliance_name',
        'cached_location',
        'cached_training',
        'is_main',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scopes',
        'is_esi_active',
        'is_director_ceo',
        'roles',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => \App\Casts\EsiToken::class,
            'refresh_token' => \App\Casts\EsiToken::class,
            'character_id' => 'integer',
            'corporation_id' => 'integer',
            'alliance_id' => 'integer',
            'is_main' => 'boolean',
            'is_esi_active' => 'boolean',
            'is_director_ceo' => 'boolean',
            'token_expires_at' => 'datetime',
            'scopes' => 'array',
            'roles' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getPortraitUrlAttribute(): string
    {
        if (DemoIds::isDemo($this->character_id)) {
            return DemoIds::portraitUrl($this->character_id);
        }

        return "https://images.evetech.net/characters/{$this->character_id}/portrait?size=128";
    }

    public function getCorporationLogoAttribute(): string
    {
        if (DemoIds::isDemo($this->corporation_id)) {
            return DemoIds::logoUrl($this->corporation_id);
        }

        return "https://images.evetech.net/corporations/{$this->corporation_id}/logo?size=64";
    }

    public function getAllianceLogoAttribute(): ?string
    {
        if (DemoIds::isDemo($this->alliance_id)) {
            return DemoIds::logoUrl($this->alliance_id);
        }

        return $this->alliance_id
            ? "https://images.evetech.net/alliances/{$this->alliance_id}/logo?size=64"
            : null;
    }

    public function isTokenExpired(): bool
    {
        return ! $this->token_expires_at || $this->token_expires_at->isPast();
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? []);
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles ?? []);
    }

    protected function getEsiClient(): \App\Services\EsiService
    {
        static $client;

        return $client ??= app(\App\Services\EsiService::class);
    }

    public function resolveCurrentSystem(): ?string
    {
        $positionData = $this->getEsiClient()->fetchPilotCurrentPosition($this);

        if (! $positionData || ! isset($positionData['solar_system_id'])) {
            return null;
        }

        $systemMetadata = $this->getEsiClient()->getSystem($positionData['solar_system_id']);

        return $systemMetadata['name'] ?? null;
    }

    public function resolveActiveTraining(): ?string
    {
        $queueData = $this->getEsiClient()->fetchPilotTrainingQueue($this);

        if (! $queueData || ! is_array($queueData)) {
            return null;
        }

        $activeEntry = null;
        foreach ($queueData as $entry) {
            if (isset($entry['queue_position']) && $entry['queue_position'] === 0) {
                $activeEntry = $entry;
                break;
            }
        }

        if (! $activeEntry || ! isset($activeEntry['skill_id'])) {
            return null;
        }

        $skillMetadata = $this->getEsiClient()->getTypeInfo($activeEntry['skill_id']);
        $skillTitle = $skillMetadata['name'] ?? "Skill {$activeEntry['skill_id']}";
        $targetLevel = $activeEntry['finished_level'] ?? '?';

        return "{$skillTitle} {$targetLevel}";
    }
}
