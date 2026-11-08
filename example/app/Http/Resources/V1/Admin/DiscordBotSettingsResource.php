<?php

namespace App\Http\Resources\V1\Admin;

use App\Models\DiscordBotSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The welcome bot settings. The token itself is never returned.
 *
 * @mixin DiscordBotSetting
 */
class DiscordBotSettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'is_enabled' => (bool) $this->is_enabled,
            /** A token is stored (it cannot be read back). */
            'has_bot_token' => $this->bot_token !== null && $this->bot_token !== '',
            'bot_name' => (string) $this->bot_name,
            'bot_avatar_url' => $this->bot_avatar_url,
            'welcome_channel_id' => $this->welcome_channel_id,
            'welcome_message' => (string) $this->welcome_message,
        ];
    }
}
