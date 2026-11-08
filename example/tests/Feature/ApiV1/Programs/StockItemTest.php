<?php

namespace Tests\Feature\ApiV1\Programs;

use App\Models\EveType;
use App\Models\IndustryStockItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\LegacyApi\Programs\ProgramsFixtures;
use Tests\TestCase;

class StockItemTest extends TestCase
{
    use ProgramsFixtures, RefreshDatabase;

    private function manager(): User
    {
        return $this->makeUser(['is_stock_manager_enabled' => true]);
    }

    private function item(User $user, int $qty = 5): IndustryStockItem
    {
        return IndustryStockItem::create(['user_id' => $user->id, 'type_id' => 34, 'type_name' => 'Tritanium', 'desired_quantity' => $qty]);
    }

    public function test_requires_auth(): void
    {
        $this->postJson('/api/v1/stock/items', [])->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->putJson('/api/v1/stock/items/1', [])->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->deleteJson('/api/v1/stock/items/1')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
    }

    /**
     * @return array<string, array{0: array<string, bool>}>
     */
    public static function nonStockManagers(): array
    {
        return [
            'regular user' => [['is_stock_manager_enabled' => false]],
            'admin without the flag' => [['is_admin' => true, 'is_stock_manager_enabled' => false]],
        ];
    }

    #[DataProvider('nonStockManagers')]
    public function test_forbidden_without_the_stock_manager_flag(array $attrs): void
    {
        EveType::create(['type_id' => 34, 'name' => 'Tritanium', 'published' => true]);
        $user = $this->makeUser($attrs);
        $item = $this->item($user);

        // 403 comes before validation and before the item lookup.
        $this->actingAs($user)->postJson('/api/v1/stock/items', [])->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->actingAs($user)->putJson("/api/v1/stock/items/{$item->id}", [])->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->actingAs($user)->putJson('/api/v1/stock/items/99999', [])->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->actingAs($user)->deleteJson("/api/v1/stock/items/{$item->id}")->assertForbidden()->assertJsonPath('code', 'forbidden');

        $this->assertSame(5, $item->fresh()->desired_quantity);
    }

    public function test_store_returns_201_with_the_item(): void
    {
        EveType::create(['type_id' => 34, 'name' => 'Tritanium', 'published' => true]);
        $user = $this->manager();

        $response = $this->actingAs($user)->postJson('/api/v1/stock/items', ['type_id' => 34, 'desired_quantity' => 1000])
            ->assertCreated()
            ->assertJsonPath('data.type_id', 34)
            ->assertJsonPath('data.type_name', 'Tritanium')
            ->assertJsonPath('data.desired_quantity', 1000);

        $item = IndustryStockItem::firstOrFail();
        $this->assertSame($user->id, $item->user_id);
        $this->assertSame($item->id, $response->json('data.id'));
        $this->assertSame(['id', 'type_id', 'type_name', 'desired_quantity'], array_keys($response->json('data')));
    }

    public function test_store_for_an_already_tracked_type_updates_the_quantity(): void
    {
        EveType::create(['type_id' => 34, 'name' => 'Tritanium', 'published' => true]);
        $user = $this->manager();

        $this->actingAs($user)->postJson('/api/v1/stock/items', ['type_id' => 34, 'desired_quantity' => 1])->assertCreated();
        $this->actingAs($user)->postJson('/api/v1/stock/items', ['type_id' => 34, 'desired_quantity' => 0])
            ->assertCreated()->assertJsonPath('data.desired_quantity', 0);

        $this->assertSame(1, IndustryStockItem::count());
    }

    public function test_store_validation_is_422(): void
    {
        $user = $this->manager();

        $this->actingAs($user)->postJson('/api/v1/stock/items', [])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['type_id', 'desired_quantity']);

        $this->actingAs($user)->postJson('/api/v1/stock/items', ['type_id' => 999999, 'desired_quantity' => -1])
            ->assertStatus(422)->assertJsonValidationErrors(['type_id', 'desired_quantity']);

        $this->assertSame(0, IndustryStockItem::count());
    }

    public function test_update_changes_the_quantity(): void
    {
        $user = $this->manager();
        $item = $this->item($user);

        $this->actingAs($user)->putJson("/api/v1/stock/items/{$item->id}", ['desired_quantity' => 42])
            ->assertOk()
            ->assertExactJson(['data' => ['id' => $item->id, 'type_id' => 34, 'type_name' => 'Tritanium', 'desired_quantity' => 42], 'meta' => []]);

        $this->assertSame(42, $item->fresh()->desired_quantity);

        $this->actingAs($user)->putJson("/api/v1/stock/items/{$item->id}", [])
            ->assertStatus(422)->assertJsonValidationErrors(['desired_quantity']);
    }

    public function test_destroy_returns_204(): void
    {
        $user = $this->manager();
        $item = $this->item($user);

        $this->actingAs($user)->deleteJson("/api/v1/stock/items/{$item->id}")->assertNoContent();

        $this->assertNull($item->fresh());
    }

    public function test_other_users_item_is_404_not_found(): void
    {
        $user = $this->manager();
        $theirs = $this->item($this->manager());

        $this->actingAs($user)->putJson("/api/v1/stock/items/{$theirs->id}", ['desired_quantity' => 1])
            ->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->actingAs($user)->deleteJson("/api/v1/stock/items/{$theirs->id}")
            ->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->actingAs($user)->deleteJson('/api/v1/stock/items/99999')
            ->assertNotFound()->assertJsonPath('code', 'not_found');

        $this->assertSame(5, $theirs->fresh()->desired_quantity);
    }
}
