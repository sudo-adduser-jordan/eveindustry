<?php

namespace App\Http\Resources\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A batch fingerprint seen more than once. It marks a recurring batch only; it does not say which item is invalid.
 */
class EsiDiagnosticBatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            /** @var string */
            'fingerprint' => $r['fingerprint'],
            /** @var string */
            'operation' => $r['operation'],
            /** @var string|null */
            'dataset' => $r['dataset'],
            /** @var string */
            'reason' => $r['reason'],
            /** @var int */
            'occurrences' => $r['occurrences'],
            /** @var int Distinct subject characters. */
            'characters' => $r['characters'],
            /** @var int|null */
            'batch_size' => $r['batch_size'],
            /** @var string */
            'first_seen' => $r['first_seen'],
            /** @var string */
            'last_seen' => $r['last_seen'],
            /** @var int|null An example subject character. */
            'character_id' => $r['character_id'],
        ];
    }
}
