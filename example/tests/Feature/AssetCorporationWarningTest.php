<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\User;
use App\Services\AssetService;
use App\Services\EsiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AssetCorporationWarningTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'is_active' => true,
        ]);
    }

    private function createMainCharacter(array $overrides = []): Character
    {
        return Character::create(array_merge([
            'character_id' => 12345,
            'user_id' => $this->user->id,
            'name' => 'Main Char',
            'corporation_id' => 98000,
            'corporation_name' => 'Test Corp',
            'is_main' => true,
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'scopes' => [
                'esi-assets.read_assets.v1',
                'esi-assets.read_corporation_assets.v1',
                'esi-characters.read_corporation_roles.v1',
            ],
        ], $overrides));
    }

    // -----------------------------------------------------------------------
    // getCorporationAssetsWarning unit-level tests
    // -----------------------------------------------------------------------

    public function test_warning_when_no_main_character(): void
    {
        $esiMock = Mockery::mock(EsiService::class);
        $service = new AssetService($esiMock);

        $warning = $service->getCorporationAssetsWarning($this->user->id);

        $this->assertNotNull($warning);
        $this->assertStringContainsStringIgnoringCase('main character', $warning);
    }

    public function test_warning_when_main_character_lacks_corp_assets_scope(): void
    {
        $this->createMainCharacter(['scopes' => ['esi-assets.read_assets.v1']]);

        $esiMock = Mockery::mock(EsiService::class);
        $service = new AssetService($esiMock);

        $warning = $service->getCorporationAssetsWarning($this->user->id);

        $this->assertNotNull($warning);
        $this->assertStringContainsStringIgnoringCase('scope', $warning);
    }

    public function test_warning_when_main_character_is_not_director(): void
    {
        $this->createMainCharacter([
            'scopes' => [
                'esi-assets.read_assets.v1',
                'esi-assets.read_corporation_assets.v1',
                'esi-characters.read_corporation_roles.v1',
            ],
        ]);

        $esiMock = Mockery::mock(EsiService::class);
        $esiMock->shouldReceive('getCharacterRoles')
            ->once()
            ->andReturn(['roles' => ['Accountant']]);

        $service = new AssetService($esiMock);

        $warning = $service->getCorporationAssetsWarning($this->user->id);

        $this->assertNotNull($warning);
        $this->assertStringContainsStringIgnoringCase('Director', $warning);
    }

    public function test_no_warning_when_main_character_is_director(): void
    {
        $this->createMainCharacter();

        $esiMock = Mockery::mock(EsiService::class);
        $esiMock->shouldReceive('getCharacterRoles')
            ->once()
            ->andReturn(['roles' => ['Director']]);

        $service = new AssetService($esiMock);

        $warning = $service->getCorporationAssetsWarning($this->user->id);

        $this->assertNull($warning);
    }

    public function test_warning_when_main_character_is_only_station_manager(): void
    {
        $this->createMainCharacter();

        $esiMock = Mockery::mock(EsiService::class);
        $esiMock->shouldReceive('getCharacterRoles')
            ->once()
            ->andReturn(['roles' => ['Station_Manager']]);

        $service = new AssetService($esiMock);

        $warning = $service->getCorporationAssetsWarning($this->user->id);

        // Station_Manager alone cannot read corporation assets: Director or CEO is required.
        $this->assertNotNull($warning);
        $this->assertStringContainsString('Director or CEO', $warning);
        $this->assertStringNotContainsString('Station Manager', $warning);
    }

    public function test_no_warning_when_main_character_is_ceo(): void
    {
        $this->createMainCharacter();

        $esiMock = Mockery::mock(EsiService::class);
        $esiMock->shouldReceive('getCharacterRoles')
            ->once()
            ->andReturn(['roles' => ['CEO']]);

        $service = new AssetService($esiMock);

        $this->assertNull($service->getCorporationAssetsWarning($this->user->id));
    }

    public function test_no_director_check_when_roles_scope_absent(): void
    {
        $this->createMainCharacter([
            'scopes' => [
                'esi-assets.read_assets.v1',
                'esi-assets.read_corporation_assets.v1',
                // no esi-characters.read_corporation_roles.v1
            ],
        ]);

        $esiMock = Mockery::mock(EsiService::class);
        // getCharacterRoles must NOT be called when the scope is absent
        $esiMock->shouldNotReceive('getCharacterRoles');

        $service = new AssetService($esiMock);

        $warning = $service->getCorporationAssetsWarning($this->user->id);

        // No warning because we cannot verify the director role without the scope
        $this->assertNull($warning);
    }
}
