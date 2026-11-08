<?php

namespace App\Http\Resources\V1\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A user as the admin user list shows them.
 *
 * @mixin User
 */
class AdminUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'display_name' => (string) $this->display_name,
            'email' => $this->email,
            /** @var string|null Main character portrait. */
            'portrait_url' => $this->mainCharacter?->portrait_url,
            'character_count' => (int) ($this->characters_count ?? $this->characters->count()),
            /** @var 'super_admin'|'admin'|'user' */
            'role' => $this->is_super_admin ? 'super_admin' : ($this->is_admin ? 'admin' : 'user'),
            'is_active' => (bool) $this->is_active,
            'is_stock_manager_enabled' => (bool) $this->is_stock_manager_enabled,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
