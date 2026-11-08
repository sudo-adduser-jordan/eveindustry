<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Http\Requests\Api\V1\ApiRequest;

class DiscordBotUpdateRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Left out or empty keeps the stored token. */
            'bot_token' => ['nullable', 'string', 'max:255'],
            /** Remove the stored token (ignored when a new `bot_token` is sent). */
            'clear_bot_token' => ['boolean'],
            'bot_name' => ['required', 'string', 'max:80'],
            'bot_avatar_url' => ['nullable', 'url', 'max:512'],
            /** A Discord snowflake, 17 to 20 digits. */
            'welcome_channel_id' => ['nullable', 'string', 'regex:/^\d{17,20}$/'],
            /** `{user}` is replaced with the mention of the new member. */
            'welcome_message' => ['required', 'string', 'max:2000'],
            /** Off when left out. */
            'is_enabled' => ['boolean'],
        ];
    }
}
