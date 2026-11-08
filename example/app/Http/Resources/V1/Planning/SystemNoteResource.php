<?php

namespace App\Http\Resources\V1\Planning;

use App\Models\WholemapSystemNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The user's note on a system (one per system).
 *
 * @mixin WholemapSystemNote
 */
class SystemNoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var int */
            'id' => $this->id,
            /** @var int */
            'system_id' => $this->system_id,
            /** @var string|null */
            'system_name' => $this->system_name,
            /** @var string One of unknown, friendly, occupied, hostile. */
            'status' => $this->status,
            /** @var string|null */
            'comment' => $this->comment,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
