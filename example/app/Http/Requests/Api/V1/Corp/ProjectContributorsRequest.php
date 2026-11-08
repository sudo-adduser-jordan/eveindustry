<?php

namespace App\Http\Requests\Api\V1\Corp;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Filters for the project contributor leaderboard.
 */
class ProjectContributorsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            /** Only projects modified on or after this day (Y-m-d). */
            'date_from' => 'nullable|date_format:Y-m-d',
            /** Only projects modified on or before this day (Y-m-d). */
            'date_to' => 'nullable|date_format:Y-m-d|after_or_equal:date_from',
            /** Case-insensitive part of the project name. */
            'project_name' => 'nullable|string|max:255',
        ], $this->paginationRules());
    }

    public function dateFrom(): ?string
    {
        return $this->validated('date_from');
    }

    public function dateTo(): ?string
    {
        return $this->validated('date_to');
    }

    public function projectName(): ?string
    {
        $name = trim((string) $this->validated('project_name'));

        return $name === '' ? null : $name;
    }
}
