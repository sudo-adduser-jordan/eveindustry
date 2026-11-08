<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Multipart form: the banner is a file upload.
 */
class SponsorStoreRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'url' => 'required|url|max:255',
            /** Banner image, up to 2 MB. */
            'image' => 'required|image|mimes:jpeg,png,gif,webp,svg|max:2048',
            /** Default true. */
            'is_active' => 'boolean',
            /** Lower shows first. Default 0. */
            'sort_order' => 'integer|min:0',
        ];
    }
}
