<?php

namespace App\Http\Requests\Api\V1\Planning;

use App\Http\Requests\Api\V1\ApiRequest;

class StationConfigStoreRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'structure_type' => 'required|string|in:none,raitaru,azbel,sotiyo,athanor,tatara',
            'rig_me' => 'required|string|in:none,t1,t2',
            'rig_te' => 'required|string|in:none,t1,t2',
            'system_security' => 'required|string|in:highsec,lowsec,nullsec',
            /** Making a config the default clears the flag on the user's others. */
            'is_default' => 'boolean',
        ];
    }
}
