<?php

namespace Tests\Unit;

use App\Models\PublicContract;
use App\Models\PublicContractItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImportPublicContractsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_runs_import_synchronously(): void
    {
        $contractsCsv = implode("\n", [
            'collateral,contract_id,date_expired,date_issued,days_to_complete,end_location_id,issuer_corporation_id,issuer_id,price,reward,start_location_id,title,type,volume,for_corporation,buyout,http_last_modified,region_id,station_id,system_id,constellation_id',
            '0,100001,2026-03-06T00:00:00Z,2026-02-20T00:00:00Z,0,60003760,98000001,12345678,1000000.00,0,60003760,Test Contract,item_exchange,10.0,false,0,,10000002,,,',
        ]);

        $itemsCsv = implode("\n", [
            'is_blueprint_copy,is_included,item_id,material_efficiency,quantity,record_id,runs,time_efficiency,type_id,http_last_modified,contract_id',
            ',true,8001,,100,9001,,,34,,100001',
        ]);

        $tmpDir = sys_get_temp_dir().'/test_pc_cmd_'.uniqid();
        mkdir($tmpDir, 0755, true);
        file_put_contents($tmpDir.'/contracts.csv', $contractsCsv);
        file_put_contents($tmpDir.'/contract_items.csv', $itemsCsv);

        $tarFile = sys_get_temp_dir().'/test_pc_cmd_'.uniqid().'.tar.bz2';
        exec('tar -cjf '.escapeshellarg($tarFile).' -C '.escapeshellarg($tmpDir).' . 2>&1');
        array_map('unlink', glob($tmpDir.'/*'));
        rmdir($tmpDir);

        $content = file_get_contents($tarFile);
        unlink($tarFile);

        Http::fake([
            'data.everef.net/*' => Http::response($content, 200),
        ]);

        $this->artisan('contracts:import')
            ->assertSuccessful();

        $this->assertEquals(1, PublicContract::count());
        $this->assertEquals(1, PublicContractItem::count());
    }
}
