<?php

namespace App\Http\Resources\V1\Heavy;

use App\Http\Resources\V1\Planning\ConnectionResource;
use App\Http\Resources\V1\Planning\SignatureResource;
use App\Http\Resources\V1\Planning\SystemNoteResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The wormhole map: signatures, connections and system notes of the last 24 hours.
 */
class WholemapMapResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            /** @var list<SignatureResource> Newest first; narrowed to one system by `system_id`. Each carries its connection, when it has one. */
            'signatures' => SignatureResource::collection($r['signatures'])->resolve($request),
            /** @var list<ConnectionResource> Newest first; always the whole chain. */
            'connections' => ConnectionResource::collection($r['connections'])->resolve($request),
            /** @var list<SystemNoteResource> */
            'notes' => SystemNoteResource::collection($r['notes']->values())->resolve($request),
            /** @var list<array{system_id: int, system_name: string|null}> Every system a signature or connection mentions. */
            'systems' => $r['systems']->map(fn ($name, $id) => ['system_id' => (int) $id, 'system_name' => $name])->values()->all(),
        ];
    }
}
