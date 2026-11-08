<?php

namespace Tests\Feature\ApiV1\Corp;

use App\Models\EveType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\LegacyApi\Corp\CorpFixtures;
use Tests\TestCase;

class StockSearchTest extends TestCase
{
    use CorpFixtures, RefreshDatabase;

    public function test_requires_auth(): void
    {
        $this->getJson('/api/v1/stock/search?q=trit')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
    }

    public function test_search_envelope(): void
    {
        EveType::create(['type_id' => 34, 'name' => 'Tritanium', 'published' => true]);
        EveType::create(['type_id' => 9999, 'name' => 'Compressed Tritanium', 'published' => true]);
        EveType::create(['type_id' => 36, 'name' => 'Unpublished Trit', 'published' => false]);
        $user = $this->makeUser(['is_stock_manager_enabled' => true]);

        $this->actingAs($user)->getJson('/api/v1/stock/search?q=trit')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    ['type_id' => 9999, 'name' => 'Compressed Tritanium', 'icon_url' => 'https://images.evetech.net/types/9999/icon?size=32'],
                    ['type_id' => 34, 'name' => 'Tritanium', 'icon_url' => 'https://images.evetech.net/types/34/icon?size=32'],
                ],
                'meta' => ['limit' => 20],
            ]);
    }

    public function test_capped_at_twenty(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            EveType::create(['type_id' => 1000 + $i, 'name' => sprintf('Widget %02d', $i), 'published' => true]);
        }
        $user = $this->makeUser(['is_stock_manager_enabled' => true]);

        $this->actingAs($user)->getJson('/api/v1/stock/search?q=widget')
            ->assertOk()
            ->assertJsonCount(20, 'data');
    }

    public function test_missing_or_blank_query_is_422(): void
    {
        $user = $this->makeUser(['is_stock_manager_enabled' => true]);

        foreach (['/api/v1/stock/search', '/api/v1/stock/search?q=%20%20', '/api/v1/stock/search?q='.str_repeat('a', 101)] as $url) {
            $this->actingAs($user)->getJson($url)
                ->assertStatus(422)
                ->assertJsonPath('code', 'validation_failed')
                ->assertJsonValidationErrors(['q']);
        }
    }

    /**
     * @return array<string, array{0: array<string, bool>}>
     */
    public static function nonStockManagers(): array
    {
        return [
            'regular user' => [['is_stock_manager_enabled' => false]],
            'admin without the flag' => [['is_admin' => true, 'is_stock_manager_enabled' => false]],
            'super admin without the flag' => [['is_super_admin' => true, 'is_stock_manager_enabled' => false]],
        ];
    }

    #[DataProvider('nonStockManagers')]
    public function test_forbidden_without_the_stock_manager_flag(array $attrs): void
    {
        $user = $this->makeUser($attrs);

        // 403 comes before validation, so a missing q still reads as forbidden.
        foreach (['/api/v1/stock/search?q=trit', '/api/v1/stock/search'] as $url) {
            $this->actingAs($user)->getJson($url)
                ->assertStatus(403)
                ->assertJsonPath('code', 'forbidden');
        }
    }
}
