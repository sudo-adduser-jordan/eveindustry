<?php

namespace Tests\Unit;

use App\Jobs\ImportPublicContracts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ImportPublicContractsJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
    }

    /**
     * Build a tar.bz2 archive containing CSV files.
     */
    private function makeTarBz2(string $contractsCsv = '', string $itemsCsv = ''): string
    {
        $tmpDir = sys_get_temp_dir().'/test_pc_'.uniqid();
        mkdir($tmpDir, 0755, true);

        if ($contractsCsv !== '') {
            file_put_contents($tmpDir.'/contracts.csv', $contractsCsv);
        }

        if ($itemsCsv !== '') {
            file_put_contents($tmpDir.'/contract_items.csv', $itemsCsv);
        }

        $tarFile = sys_get_temp_dir().'/test_pc_'.uniqid().'.tar.bz2';
        exec('tar -cjf '.escapeshellarg($tarFile).' -C '.escapeshellarg($tmpDir).' . 2>&1', $output, $exitCode);

        // Clean up temp directory
        array_map('unlink', glob($tmpDir.'/*'));
        rmdir($tmpDir);

        $this->assertEquals(0, $exitCode, 'tar command failed: '.implode("\n", $output));

        $content = file_get_contents($tarFile);
        unlink($tarFile);

        return $content;
    }

    public function test_job_is_queueable(): void
    {
        $job = new ImportPublicContracts;
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, $job);
    }

    public function test_job_upsert_chunk_size_constant(): void
    {
        $this->assertEquals(500, ImportPublicContracts::UPSERT_CHUNK_SIZE);
    }

    public function test_job_skips_on_http_failure(): void
    {
        Http::fake([
            'data.everef.net/*' => Http::response(null, 503),
        ]);

        $job = new ImportPublicContracts;
        try {
            (new ImportPublicContracts)->handle();
            $this->fail('A failed download must fail the job.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 503', $exception->getMessage());
        }
        $this->assertSame(1, (int) DB::table('public_contract_snapshot_state')->where('id', 1)->value('active_generation_id'));
    }

    public function test_job_imports_contracts_and_items_from_csv(): void
    {
        $contractsCsv = implode("\n", [
            'collateral,contract_id,date_expired,date_issued,days_to_complete,end_location_id,issuer_corporation_id,issuer_id,price,reward,start_location_id,title,type,volume,for_corporation,buyout,http_last_modified,region_id,station_id,system_id,constellation_id',
            '0,100001,2026-03-06T00:00:00Z,2026-02-20T00:00:00Z,0,60003760,98000001,12345678,1500000.50,0,60003760,Test Contract,item_exchange,10.0,false,0,2026-02-20T12:00:00Z,10000002,60003760,30000142,20000020',
            '0,100002,2026-03-07T00:00:00Z,2026-02-21T00:00:00Z,0,60003760,98000002,87654321,5000000.00,,60003760,Another Contract,item_exchange,5.0,true,,2026-02-21T12:00:00Z,10000002,60003760,30000142,20000020',
        ]);

        $itemsCsv = implode("\n", [
            'is_blueprint_copy,is_included,item_id,material_efficiency,quantity,record_id,runs,time_efficiency,type_id,http_last_modified,contract_id',
            ',true,8001,,100,9001,,,34,2026-02-20T12:00:00Z,100001',
            ',true,8002,,50,9002,,,35,2026-02-21T12:00:00Z,100002',
            ',true,8003,,25,9003,,,36,2026-02-21T12:00:00Z,100002',
        ]);

        $archive = $this->makeTarBz2($contractsCsv, $itemsCsv);

        Http::fake([
            'data.everef.net/*' => Http::response($archive, 200),
        ]);

        $job = new ImportPublicContracts;
        $job->handle();

        $generationId = (int) DB::table('public_contract_snapshot_state')->where('id', 1)->value('active_generation_id');
        $this->assertEquals(2, DB::table('public_contract_snapshot_contracts')->where('generation_id', $generationId)->count());
        $this->assertEquals(3, DB::table('public_contract_snapshot_items')->where('generation_id', $generationId)->count());

        $this->assertDatabaseHas('public_contract_snapshot_contracts', [
            'generation_id' => $generationId,
            'contract_id' => 100001,
            'region_id' => 10000002,
            'type' => 'item_exchange',
            'price' => 1500000.50,
            'station_id' => 60003760,
            'system_id' => 30000142,
            'constellation_id' => 20000020,
        ]);

        $this->assertDatabaseHas('public_contract_snapshot_items', [
            'generation_id' => $generationId,
            'contract_id' => 100001,
            'type_id' => 34,
            'quantity' => 100,
        ]);

        $this->assertDatabaseHas('public_contract_snapshot_items', [
            'generation_id' => $generationId,
            'contract_id' => 100002,
            'type_id' => 36,
            'quantity' => 25,
        ]);
    }

    public function test_job_uses_correct_url(): void
    {
        Http::fake([
            'data.everef.net/*' => Http::response(null, 404),
        ]);

        $job = new ImportPublicContracts;
        try {
            (new ImportPublicContracts)->handle();
            $this->fail('An HTTP error must fail the job.');
        } catch (RuntimeException) {
            // Expected: retain the previous active snapshot and let the queue retry.
        }

        Http::assertSent(function ($request) {
            return str_contains((string) $request->url(), 'data.everef.net/public-contracts/public-contracts-latest.v2.tar.bz2');
        });
    }

    public function test_job_handles_missing_csv_files(): void
    {
        // Create an archive without the expected CSV files
        $tmpDir = sys_get_temp_dir().'/test_pc_'.uniqid();
        mkdir($tmpDir, 0755, true);
        file_put_contents($tmpDir.'/meta.json', '{"datasource":"tranquility"}');

        $tarFile = sys_get_temp_dir().'/test_pc_'.uniqid().'.tar.bz2';
        exec('tar -cjf '.escapeshellarg($tarFile).' -C '.escapeshellarg($tmpDir).' . 2>&1');
        unlink($tmpDir.'/meta.json');
        rmdir($tmpDir);

        $content = file_get_contents($tarFile);
        unlink($tarFile);

        Http::fake([
            'data.everef.net/*' => Http::response($content, 200),
        ]);

        $job = new ImportPublicContracts;
        try {
            (new ImportPublicContracts)->handle();
            $this->fail('An archive missing its CSV files must fail the job.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('missing', $exception->getMessage());
        }
        $this->assertSame(1, (int) DB::table('public_contract_snapshot_state')->where('id', 1)->value('active_generation_id'));
    }

    public function test_job_handles_contracts_without_items(): void
    {
        $contractsCsv = implode("\n", [
            'collateral,contract_id,date_expired,date_issued,days_to_complete,end_location_id,issuer_corporation_id,issuer_id,price,reward,start_location_id,title,type,volume,for_corporation,buyout,http_last_modified,region_id,station_id,system_id,constellation_id',
            '0,200001,2026-03-06T00:00:00Z,2026-02-20T00:00:00Z,0,60003760,98000001,12345678,100.0,0,60003760,,item_exchange,1.0,false,0,,10000002,,,',
        ]);

        $archive = $this->makeTarBz2($contractsCsv, "contract_id,type_id,quantity,is_included\n");

        Http::fake([
            'data.everef.net/*' => Http::response($archive, 200),
        ]);

        $job = new ImportPublicContracts;
        $job->handle();
        $generationId = (int) DB::table('public_contract_snapshot_state')->where('id', 1)->value('active_generation_id');
        $this->assertEquals(1, DB::table('public_contract_snapshot_contracts')->where('generation_id', $generationId)->count());
        $this->assertEquals(0, DB::table('public_contract_snapshot_items')->where('generation_id', $generationId)->count());
    }

    public function test_job_imports_blueprint_copy_items(): void
    {
        $contractsCsv = implode("\n", [
            'collateral,contract_id,date_expired,date_issued,days_to_complete,end_location_id,issuer_corporation_id,issuer_id,price,reward,start_location_id,title,type,volume,for_corporation,buyout,http_last_modified,region_id,station_id,system_id,constellation_id',
            '0,300001,2026-03-06T00:00:00Z,2026-02-20T00:00:00Z,0,60003760,98000001,12345678,500000.0,0,60003760,BPC Sale,item_exchange,0.01,false,0,,10000002,,,',
        ]);

        $itemsCsv = implode("\n", [
            'is_blueprint_copy,is_included,item_id,material_efficiency,quantity,record_id,runs,time_efficiency,type_id,http_last_modified,contract_id',
            'true,true,8001,10,1,9001,300,20,681,2026-02-20T12:00:00Z,300001',
        ]);

        $archive = $this->makeTarBz2($contractsCsv, $itemsCsv);

        Http::fake([
            'data.everef.net/*' => Http::response($archive, 200),
        ]);

        $job = new ImportPublicContracts;
        $job->handle();

        $generationId = (int) DB::table('public_contract_snapshot_state')->where('id', 1)->value('active_generation_id');
        $this->assertEquals(1, DB::table('public_contract_snapshot_contracts')->where('generation_id', $generationId)->count());
        $this->assertEquals(1, DB::table('public_contract_snapshot_items')->where('generation_id', $generationId)->count());

        $this->assertDatabaseHas('public_contract_snapshot_items', [
            'generation_id' => $generationId,
            'contract_id' => 300001,
            'type_id' => 681,
            'is_blueprint_copy' => true,
            'material_efficiency' => 10,
            'time_efficiency' => 20,
            'runs' => 300,
        ]);
    }

    public function test_failure_after_staging_contracts_keeps_previous_generation_active(): void
    {
        $baseline = $this->makeTarBz2(
            "contract_id,region_id,type,price\n9001,10000002,item_exchange,100\n",
            "contract_id,type_id,quantity,is_included\n9001,34,1,true\n"
        );
        $broken = $this->makeTarBz2(
            "contract_id,region_id,type,price\n9002,10000002,item_exchange,200\n",
            "contract_id,type_id,quantity,is_included\n9002,\n"
        );
        Http::fake(['data.everef.net/*' => Http::sequence()->push($baseline, 200)->push($broken, 200)]);
        (new ImportPublicContracts)->handle();
        $activeBefore = (int) DB::table('public_contract_snapshot_state')->where('id', 1)->value('active_generation_id');

        try {
            (new ImportPublicContracts)->handle();
            $this->fail('Malformed item input must fail publication.');
        } catch (RuntimeException) {
            // Expected: staged rows are not visible to the active reader.
        }

        $activeAfter = (int) DB::table('public_contract_snapshot_state')->where('id', 1)->value('active_generation_id');
        $this->assertSame($activeBefore, $activeAfter);
        $this->assertSame(1, DB::table('public_contract_snapshot_contracts')->where('generation_id', $activeAfter)->count());
        $this->assertSame(1, DB::table('public_contract_snapshot_items')->where('generation_id', $activeAfter)->count());
    }

    public function test_valid_empty_snapshot_replaces_previous_active_generation(): void
    {
        $baseline = $this->makeTarBz2(
            "contract_id,region_id,type,price\n9101,10000002,item_exchange,100\n",
            "contract_id,type_id,quantity,is_included\n"
        );
        $empty = $this->makeTarBz2(
            "contract_id,region_id,type,price\n",
            "contract_id,type_id,quantity,is_included\n"
        );
        Http::fake(['data.everef.net/*' => Http::sequence()->push($baseline, 200)->push($empty, 200)]);
        (new ImportPublicContracts)->handle();
        $activeBefore = (int) DB::table('public_contract_snapshot_state')->where('id', 1)->value('active_generation_id');

        (new ImportPublicContracts)->handle();

        $activeAfter = (int) DB::table('public_contract_snapshot_state')->where('id', 1)->value('active_generation_id');
        $this->assertGreaterThan($activeBefore, $activeAfter);
        $this->assertSame(0, DB::table('public_contract_snapshot_contracts')->where('generation_id', $activeAfter)->count());
        $this->assertSame(0, DB::table('public_contract_snapshot_items')->where('generation_id', $activeAfter)->count());
        $this->assertSame(1, DB::table('public_contract_snapshot_contracts')->where('generation_id', $activeBefore)->count());
    }
}
