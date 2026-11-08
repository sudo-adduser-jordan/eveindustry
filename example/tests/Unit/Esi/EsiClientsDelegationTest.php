<?php

namespace Tests\Unit\Esi;

use App\Models\Character;
use App\Models\User;
use App\Services\Esi\CharacterClient;
use App\Services\Esi\CorporationClient;
use App\Services\Esi\EsiAuthClient;
use App\Services\Esi\EsiHttpClient;
use App\Services\Esi\IndustryClient;
use App\Services\Esi\MarketClient;
use App\Services\Esi\PlanetaryClient;
use App\Services\Esi\SdeClient;
use App\Services\Esi\UniverseClient;
use App\Services\EsiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Each focused ESI client must be resolvable from the container, do its
 * own work against a faked ESI, and be reachable through the EsiService
 * facade (which has to work as a bare `new EsiService`).
 */
class EsiClientsDelegationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::flush();
    }

    private function makeCharacter(): Character
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'clients@test.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        return Character::create([
            'character_id' => 4242,
            'user_id' => $user->id,
            'name' => 'Test Character',
            'corporation_id' => 98000,
            'corporation_name' => 'Test Corp',
            'access_token' => 'valid-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['esi-industry.read_character_jobs.v1'],
            'is_esi_active' => true,
        ]);
    }

    public function test_http_client_makes_public_request_and_tracks_error_limit(): void
    {
        Http::fake(['*/universe/systems/1/' => Http::response(['name' => 'A'], 200, [
            'X-Esi-Error-Limit-Remain' => '15',
            'X-Esi-Error-Limit-Reset' => '30',
        ])]);

        $client = app(EsiHttpClient::class);
        $response = $client->publicRequest('get', '/universe/systems/1/');

        $this->assertSame(['name' => 'A'], $response->json());
        $this->assertTrue($client->isEsiErrorLimited());
        // The facade reads the same shared budget.
        $this->assertTrue((new EsiService)->isEsiErrorLimited());
        $this->assertSame(30, (new EsiService)->esiErrorResetSeconds());
    }

    public function test_http_client_maps_endpoints_to_rate_limit_groups(): void
    {
        $client = app(EsiHttpClient::class);

        $this->assertSame('char-asset', $client->resolveRateLimitGroup('/characters/1/assets/'));
        $this->assertSame('market-order', $client->resolveRateLimitGroup('/latest/markets/10000002/orders/'));
        $this->assertSame('legacy', $client->resolveRateLimitGroup('/alliances/1/'));
    }

    public function test_auth_client_refreshes_token_and_facade_delegates(): void
    {
        Http::fake(['login.eveonline.com/*' => Http::response(['access_token' => 'new', 'refresh_token' => 'r2', 'expires_in' => 1199], 200)]);

        $this->assertSame('new', app(EsiAuthClient::class)->refreshToken('r1')['access_token']);
        $this->assertSame('new', (new EsiService)->refreshToken('r1')['access_token']);
        Http::assertSentCount(2);
    }

    public function test_character_client_fetches_character(): void
    {
        Http::fake(['*/characters/42/' => Http::response(['name' => 'Pilot'], 200)]);

        $this->assertSame(['name' => 'Pilot'], app(CharacterClient::class)->getCharacter(42));
        Cache::flush();
        $this->assertSame(['name' => 'Pilot'], (new EsiService)->getCharacter(42));
        Http::assertSentCount(2);
    }

    public function test_corporation_client_fetches_corporation(): void
    {
        Http::fake(['*/corporations/98000/' => Http::response(['name' => 'Corp'], 200)]);

        $this->assertSame(['name' => 'Corp'], app(CorporationClient::class)->getCorporation(98000));
        Cache::flush();
        $this->assertSame(['name' => 'Corp'], (new EsiService)->getCorporationInfo(98000));
        Http::assertSentCount(2);
    }

    public function test_universe_client_fetches_system(): void
    {
        Http::fake(['*/universe/systems/30000142/' => Http::response(['name' => 'Jita'], 200)]);

        $this->assertSame(['name' => 'Jita'], app(UniverseClient::class)->getSystem(30000142));
        Cache::flush();
        $this->assertSame(['name' => 'Jita'], (new EsiService)->getSystem(30000142));
        Http::assertSentCount(2);
    }

    public function test_image_url_stays_a_static_on_the_facade(): void
    {
        $this->assertSame(
            'https://images.evetech.net/types/34/render?size=64',
            EsiService::getTypeImageUrl(34, 'render', 64)
        );
    }

    public function test_market_client_fetches_prices(): void
    {
        Http::fake(['*/markets/prices/' => Http::response([['type_id' => 34, 'average_price' => 5.5]], 200)]);

        $this->assertSame(34, app(MarketClient::class)->getMarketPrices()[0]['type_id']);
        Cache::flush();
        $this->assertSame(34, (new EsiService)->getMarketPrices()[0]['type_id']);
        Http::assertSentCount(2);
    }

    public function test_industry_client_fetches_character_jobs_with_auth_header(): void
    {
        Http::fake(['*/characters/4242/industry/jobs/' => Http::response([['job_id' => 1]], 200)]);
        $character = $this->makeCharacter();

        $this->assertSame([['job_id' => 1]], app(IndustryClient::class)->getCharacterIndustryJobs($character));
        Cache::flush();
        $this->assertSame([['job_id' => 1]], (new EsiService)->getCharacterIndustryJobs($character));
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer valid-token'));
    }

    public function test_planetary_client_fetches_planet_info(): void
    {
        Http::fake(['*/universe/planets/99/' => Http::response(['name' => 'Planet'], 200)]);

        $this->assertSame(['name' => 'Planet'], app(PlanetaryClient::class)->getPlanetInfo(99));
        Cache::flush();
        $this->assertSame(['name' => 'Planet'], (new EsiService)->getPlanetInfo(99));
        Http::assertSentCount(2);
    }

    public function test_sde_client_fetches_type_materials_from_fuzzwork(): void
    {
        Http::fake(['www.fuzzwork.co.uk/sde/api/invTypeMaterials/*' => Http::response([
            ['materialTypeID' => 34, 'quantity' => 100],
        ], 200)]);

        $materials = app(SdeClient::class)->getTypeMaterials(587);
        $this->assertSame(34, $materials[0]['type_id']);
        $this->assertSame(100, $materials[0]['quantity']);

        // Now persisted locally, so the facade answers from the DB.
        $this->assertSame($materials[0]['quantity'], (new EsiService)->getTypeMaterials(587)[0]['quantity']);
    }
}
