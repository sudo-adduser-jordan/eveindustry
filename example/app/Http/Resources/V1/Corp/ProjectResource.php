<?php

namespace App\Http\Resources\V1\Corp;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One corporation project with its progress, reward and contributors, from
 * CorporationProjectsService::getProjectsForUser().
 */
class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $p = $this->resource;
        $reward = $p['reward'] ?? null;

        return [
            'id' => (string) $p['id'],
            'name' => (string) ($p['name'] ?? ''),
            'state' => $p['state'] ?? null,
            /** @var string|null ISO 8601, as returned by ESI. */
            'last_modified' => $p['last_modified'] ?? null,
            'progress' => [
                'current' => (int) ($p['progress']['current'] ?? 0),
                'desired' => (int) ($p['progress']['desired'] ?? 0),
            ],
            'completion_pct' => (float) ($p['completion_pct'] ?? 0),
            'is_complete' => (bool) ($p['is_complete'] ?? false),
            /** @var array{initial: float, remaining: float}|null ISK reward pool, when the project has one. */
            'reward' => is_array($reward) ? [
                'initial' => (float) ($reward['initial'] ?? 0),
                'remaining' => (float) ($reward['remaining'] ?? 0),
            ] : null,
            'reward_earned' => (float) ($p['reward_earned'] ?? 0),
            /** @var list<array{character_id: int|null, character_name: string|null, contributed: int|float|null}> */
            'contributors' => array_map(fn (array $c) => [
                'character_id' => isset($c['character_id']) ? (int) $c['character_id'] : null,
                'character_name' => $c['character_name'] ?? $c['name'] ?? null,
                /** @var int|float|null Units contributed. */
                'contributed' => $c['contributed'] ?? $c['amount'] ?? $c['quantity'] ?? null,
            ], array_values($p['contributors'] ?? [])),
        ];
    }
}
