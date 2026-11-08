<?php

namespace Tests\Unit;

use App\Services\Demo\DemoDataService;
use App\Services\Esi\EsiRequestException;
use App\Services\EsiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DemoEsiResponderTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_characters_are_served_locally_without_any_http_call(): void
    {
        Http::fake();
        $user = app(DemoDataService::class)->reset(false);
        $main = $user->characters->firstWhere('is_main', true);
        $esi = app(EsiService::class);

        $skills = $esi->getCharacterSkills($main);
        $this->assertNotEmpty($skills['skills']);
        $this->assertNotEmpty($esi->getCharacterAssets($main));
        $planets = $esi->getCharacterPlanets($main);
        $this->assertCount(3, $planets);
        $details = $esi->getCharacterPlanetDetails($main, $planets[0]['planet_id']);
        $this->assertNotEmpty($details['pins']);
        $this->assertSame($main->name, $esi->getCharacter($main->character_id)['name']);
        $this->assertSame($main->corporation_name, $esi->getCorporation($main->corporation_id)['name']);
        $this->assertSame($main->corporation_id, $esi->getCharacterAffiliation($main->character_id)['corporation_id']);
        $this->assertContains('Director', $esi->getCharacterRoles($main)['roles']);

        Http::assertNothingSent();
    }

    public function test_assets_and_planets_are_stable_for_a_character(): void
    {
        $user = app(DemoDataService::class)->reset(false);
        $main = $user->characters->firstWhere('is_main', true);
        $esi = app(EsiService::class);

        $first = $esi->getCharacterPlanets($main);
        \Illuminate\Support\Facades\Cache::flush();

        $this->assertSame($first, $esi->getCharacterPlanets($main));
    }

    public function test_unknown_demo_endpoints_fail_like_a_missing_resource(): void
    {
        Http::fake();
        $main = app(DemoDataService::class)->reset(false)->characters->firstWhere('is_main', true);

        $this->expectException(EsiRequestException::class);
        app(\App\Services\Esi\EsiHttpClient::class)->authenticatedResponse('get', "/characters/{$main->character_id}/mail/", $main);
    }
}
