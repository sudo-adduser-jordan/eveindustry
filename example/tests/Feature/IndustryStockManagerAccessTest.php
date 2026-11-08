<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndustryStockManagerAccessTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $attrs = []): User
    {
        return User::create(array_merge([
            'name' => 'User',
            'email' => 'u'.uniqid().'@test.com',
            'is_active' => true,
        ], $attrs));
    }

    public function test_disabled_user_cannot_access_page(): void
    {
        $user = $this->makeUser(['is_stock_manager_enabled' => false]);

        $this->actingAs($user)
            ->get('/industry/stock-manager')
            ->assertForbidden();
    }

    public function test_admin_without_flag_cannot_access(): void
    {
        $admin = $this->makeUser(['is_admin' => true, 'is_stock_manager_enabled' => false]);

        $this->actingAs($admin)
            ->get('/industry/stock-manager')
            ->assertForbidden();
    }

    public function test_enabled_user_can_access_page(): void
    {
        $user = $this->makeUser(['is_stock_manager_enabled' => true]);

        $this->actingAs($user)
            ->get('/industry/stock-manager')
            ->assertRedirect('/app/industry/stock-manager');
    }

    public function test_disabled_user_blocked_from_all_api_endpoints(): void
    {
        $user = $this->makeUser(['is_stock_manager_enabled' => false]);

        $this->actingAs($user)
            ->getJson('/api/v1/stock/search?q=tritan')
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson('/api/v1/stock/items', ['type_id' => 34, 'desired_quantity' => 1])
            ->assertForbidden();

        $this->actingAs($user)
            ->putJson('/api/v1/stock/items/1', ['desired_quantity' => 1])
            ->assertForbidden();

        $this->actingAs($user)
            ->deleteJson('/api/v1/stock/items/1')
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson('/api/v1/stock/refresh')
            ->assertForbidden();
    }

    public function test_corp_member_without_flag_is_blocked_even_when_corpmate_has_flag(): void
    {
        $corpId = 98000123;

        $enabled = $this->makeUser(['is_stock_manager_enabled' => true]);
        Character::create([
            'character_id' => 111,
            'user_id' => $enabled->id,
            'name' => 'Director',
            'corporation_id' => $corpId,
            'is_main' => true,
        ]);

        $corpmate = $this->makeUser(['is_stock_manager_enabled' => false]);
        Character::create([
            'character_id' => 222,
            'user_id' => $corpmate->id,
            'name' => 'Corpmate',
            'corporation_id' => $corpId,
            'is_main' => true,
        ]);

        $this->actingAs($corpmate)
            ->get('/industry/stock-manager')
            ->assertForbidden();
    }
}
