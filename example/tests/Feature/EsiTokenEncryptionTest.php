<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EsiTokenEncryptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['esi.encrypt_tokens' => true]);
    }

    private function legacy(): Character
    {
        $user = User::create(['name' => 'Pilot', 'email' => 'encryption@test.example']);
        DB::table('characters')->insert([
            'character_id' => 9001, 'user_id' => $user->id, 'name' => 'Pilot', 'corporation_id' => 98000001,
            'access_token' => 'legacy-access', 'refresh_token' => 'legacy-refresh',
        ]);

        return Character::findOrFail(9001);
    }

    public function test_legacy_read_and_new_write_are_compatible(): void
    {
        $character = $this->legacy();
        $this->assertSame('legacy-access', $character->access_token);
        $character->update(['access_token' => 'rotated-access']);
        $this->assertSame('rotated-access', $character->fresh()->access_token);
        $this->assertNotSame('rotated-access', DB::table('characters')->value('access_token'));
        $this->assertArrayNotHasKey('access_token', $character->toArray());
    }

    public function test_backfill_defaults_to_dry_run_and_is_idempotent(): void
    {
        $this->legacy();
        $this->artisan('esi:encrypt-tokens')->assertSuccessful();
        $this->assertSame('legacy-refresh', DB::table('characters')->value('refresh_token'));
        $this->artisan('esi:encrypt-tokens --write')->assertSuccessful();
        $raw = DB::table('characters')->value('refresh_token');
        $this->assertNotSame('legacy-refresh', $raw);
        $this->assertSame('legacy-refresh', Character::findOrFail(9001)->refresh_token);
        $this->artisan('esi:encrypt-tokens --write')->expectsOutput('Encrypted credentials for 0 character(s).')->assertSuccessful();
        $this->assertSame($raw, DB::table('characters')->value('refresh_token'));
    }
}
