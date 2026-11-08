<?php

namespace App\Http\Requests\Api\V1\Admin;

/**
 * Multipart form, sent with POST (PHP does not parse multipart PUT bodies).
 * The banner is optional here: leaving it out keeps the current one.
 */
class SponsorUpdateRequest extends SponsorStoreRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['image' => 'nullable|image|mimes:jpeg,png,gif,webp,svg|max:2048'] + parent::rules();
    }
}
