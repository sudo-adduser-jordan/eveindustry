<?php

namespace Tests\Unit;

use App\Services\Esi\EsiHttpClient;
use App\Services\EsiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EsiServiceErrorLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['esi.coordination_store' => 'array']);
        Cache::flush();
    }

    public function test_no_observation_means_no_circuit(): void
    {
        $this->assertFalse((new EsiService)->isEsiErrorLimited());
    }

    public function test_low_authenticated_budget_opens_shared_circuit_for_all_routes(): void
    {
        Http::fake(['*' => Http::response([], 200, ['X-Esi-Error-Limit-Remain' => '5', 'X-Esi-Error-Limit-Reset' => '40'])]);
        $response = Http::get('https://example.test');
        app(EsiHttpClient::class)->trackEsiErrorLimit($response, 123);
        $service = new EsiService;
        $this->assertTrue($service->isEsiErrorLimited(456));
        $this->assertTrue($service->isEsiErrorLimited());
        $this->travel(15)->seconds();
        $this->assertSame(25, $service->esiErrorResetSeconds());
        $this->travel(26)->seconds();
        $this->assertFalse($service->isEsiErrorLimited());
    }

    public function test_420_without_legacy_headers_still_opens_shared_circuit(): void
    {
        Http::fake(['*' => Http::response([], 420)]);
        app(EsiHttpClient::class)->trackEsiErrorLimit(Http::get('https://example.test'), 123);
        $this->assertTrue((new EsiService)->isEsiErrorLimited());
    }

    public function test_healthy_response_does_not_close_an_existing_circuit_early(): void
    {
        Http::fakeSequence()->push([], 420, ['X-Esi-Error-Limit-Reset' => '40'])->push([], 200, ['X-Esi-Error-Limit-Remain' => '90', 'X-Esi-Error-Limit-Reset' => '1']);
        $client = app(EsiHttpClient::class);
        $client->trackEsiErrorLimit(Http::get('https://example.test'));
        $client->trackEsiErrorLimit(Http::get('https://example.test'));
        $this->assertSame(40, $client->esiErrorResetSeconds());
    }

    public function test_public_request_stops_during_shared_circuit(): void
    {
        Cache::put('esi.error_circuit.until', now()->timestamp + 40, 40);
        Http::fake();
        $this->assertNull(app(EsiHttpClient::class)->publicRequest('get', '/universe/types/34/'));
        Http::assertNothingSent();
    }
}
