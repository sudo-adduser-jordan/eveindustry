<?php

namespace App\Http\Resources\V1\Industry;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A reaction formula and whether the user owns it, from
 * ReactionFormulaResearchService::getReactionFormulaData().
 */
class ReactionFormulaResearchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $f = $this->resource;

        return [
            'type_id' => (int) $f->type_id,
            'name' => (string) $f->name,
            'group_id' => $f->group_id !== null ? (int) $f->group_id : null,
            /** Composite, Biochemical, Hybrid or Other. */
            'category' => (string) $f->category,
            'owned' => (bool) $f->owned,
            /** Formulas held across all characters, including those locked in running jobs. */
            'quantity' => (int) $f->quantity,
            /** @var list<string> Characters holding it. */
            'owner_names' => array_values($f->owner_names),
        ];
    }
}
