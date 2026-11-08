<?php

namespace App\Http\Requests\Api\V1\Corp;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Date range and wallet division filters for corporation donations.
 */
class DonationIndexRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            /** First day (Y-m-d). Defaults to 30 days ago. */
            'date_from' => 'nullable|date_format:Y-m-d',
            /** Last day (Y-m-d), inclusive. Defaults to today. */
            'date_to' => 'nullable|date_format:Y-m-d|after_or_equal:date_from',
            /** Corporation wallet division (1 = master wallet). Defaults to all. */
            'division' => 'nullable|integer|between:1,7',
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

    public function division(): ?int
    {
        $division = $this->validated('division');

        return $division === null ? null : (int) $division;
    }
}
