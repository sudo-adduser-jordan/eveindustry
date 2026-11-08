<?php

namespace App\Http\Requests\Api\V1\Reference;

class StationSearchRequest extends SearchRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            /** Only stations in this solar system. */
            'solar_system_id' => 'nullable|integer|min:1',
        ]);
    }

    public function solarSystemId(): ?int
    {
        $id = $this->validated('solar_system_id');

        return $id === null ? null : (int) $id;
    }
}
