<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PruneSnapshotGenerationsTest extends TestCase
{
    use RefreshDatabase;

    private function generations(): array
    {
        config(['esi.coordination_store' => 'array']);
        $ids = [];
        foreach (['published', 'published', 'published', 'failed', 'building'] as $status) {
            $ids[] = DB::table('public_contract_generations')->insertGetId([
                'status' => $status, 'created_at' => now()->subDays(2),
                'published_at' => $status === 'published' ? now()->subDays(2) : null,
            ]);
        }
        DB::table('public_contract_snapshot_state')->where('id', 1)->update(['active_generation_id' => $ids[2]]);

        return $ids;
    }

    public function test_dry_run_keeps_all_generations(): void
    {
        $ids = $this->generations();
        $this->artisan('market:snapshots-prune')->assertSuccessful();
        $this->assertSame(count($ids), DB::table('public_contract_generations')->whereIn('id', $ids)->count());
    }

    public function test_write_preserves_active_previous_and_building_generations(): void
    {
        $ids = $this->generations();
        $this->artisan('market:snapshots-prune --write')->assertSuccessful();
        $this->assertDatabaseMissing('public_contract_generations', ['id' => $ids[0]]);
        $this->assertDatabaseMissing('public_contract_generations', ['id' => $ids[3]]);
        foreach ([$ids[1], $ids[2], $ids[4]] as $id) {
            $this->assertDatabaseHas('public_contract_generations', ['id' => $id]);
        }
        $this->assertSame($ids[2], (int) DB::table('public_contract_snapshot_state')->value('active_generation_id'));
    }

    public function test_an_import_lock_prevents_contract_pruning(): void
    {
        $ids = $this->generations();
        $lock = Cache::store('array')->lock('public-contract-snapshot-writer', 1200);
        $this->assertTrue($lock->get());
        try {
            $this->artisan('market:snapshots-prune --write')->assertSuccessful();
            $this->assertDatabaseHas('public_contract_generations', ['id' => $ids[0]]);
            $this->assertDatabaseHas('public_contract_generations', ['id' => $ids[3]]);
        } finally {
            $lock->release();
        }
    }
}
