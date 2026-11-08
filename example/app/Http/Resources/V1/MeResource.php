<?php

namespace App\Http\Resources\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in user, their characters and feature flags.
 *
 * @mixin User
 */
class MeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'display_name' => (string) $this->display_name,
            /** @var string|null Main character portrait. */
            'avatar_url' => $this->avatar_url,
            /** Admin or super admin. */
            'admin' => (bool) $this->isAdmin(),
            /** Stock manager feature enabled for this user. */
            'stock_manager' => (bool) $this->hasStockManager(),
            /** Shared demo account: data is generated and resets daily; account changes are blocked. */
            'demo' => (bool) $this->isDemo(),
            /** @var string Where the source code of this running instance is available (AGPL). */
            'source_url' => (string) config('app.source_url'),
            /** @var string|null Optional community invite link. */
            'discord_url' => config('app.discord_url') ?: null,
            'features' => [
                /** Discord welcome bot admin page (work in progress, off by default). */
                'discord_bot' => (bool) config('features.discord_bot'),
            ],
            'characters' => CharacterResource::collection($this->characters),
        ];
    }
}
