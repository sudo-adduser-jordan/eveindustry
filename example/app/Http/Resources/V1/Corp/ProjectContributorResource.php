<?php

namespace App\Http\Resources\V1\Corp;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One contributor on the project leaderboard, from
 * CorporationProjectsService::buildContributorLeaderboard().
 */
class ProjectContributorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $c = $this->resource;

        return [
            /** @var int|null Null for contributors ESI names but does not identify. */
            'character_id' => isset($c['character_id']) ? (int) $c['character_id'] : null,
            'character_name' => $c['character_name'] ?? null,
            'project_count' => (int) $c['project_count'],
            /** ISK attributed across the projects: units contributed times the project's reward per unit. */
            'attributed_isk' => (float) $c['attributed_isk'],
            /** Share of all attributed ISK, percent. */
            'attributed_pct' => (float) $c['attributed_pct'],
            /** @var list<array{id: string, name: string, quantity: int, attributed_isk: float}> */
            'projects' => array_map(fn (array $p) => [
                'id' => (string) $p['id'],
                'name' => (string) $p['name'],
                'quantity' => (int) $p['quantity'],
                'attributed_isk' => (float) $p['attributed_isk'],
            ], array_values($c['projects'] ?? [])),
        ];
    }
}
