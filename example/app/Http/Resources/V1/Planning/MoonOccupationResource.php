<?php

namespace App\Http\Resources\V1\Planning;

use App\Models\MoonScanResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Who occupies a scanned moon.
 *
 * @mixin MoonScanResult
 */
class MoonOccupationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var int */
            'moon_id' => $this->id,
            'moon_name' => $this->moon_name,
            'occupied' => (bool) $this->occupied,
            /** @var int|null */
            'occupying_corporation_id' => $this->occupying_corporation_id,
            'occupying_corporation_name' => $this->occupying_corporation_name,
        ];
    }
}
