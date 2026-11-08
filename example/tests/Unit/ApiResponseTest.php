<?php

namespace Tests\Unit;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

class ApiResponseTest extends TestCase
{
    public function test_success_wraps_data_and_empty_meta_object(): void
    {
        $response = ApiResponse::success(['a' => 1]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('{"data":{"a":1},"meta":{}}', $response->getContent());
    }

    public function test_success_resolves_resources_without_double_wrapping(): void
    {
        $resource = new class(['id' => 7]) extends JsonResource
        {
            public function toArray(Request $request): array
            {
                return ['id' => $this->resource['id'], 'kind' => 'thing'];
            }
        };

        $body = ApiResponse::success($resource, ['x' => true], 201)->getData(true);

        $this->assertSame(['data' => ['id' => 7, 'kind' => 'thing'], 'meta' => ['x' => true]], $body);
    }

    public function test_null_data(): void
    {
        $this->assertSame('{"data":null,"meta":{}}', ApiResponse::success(null)->getContent());
    }

    public function test_paginated_adds_pagination_meta(): void
    {
        $paginator = new LengthAwarePaginator([['id' => 3], ['id' => 4]], 5, 2, 2);

        $body = ApiResponse::paginated($paginator, JsonResource::class, ['freshness' => ['stale' => false]])->getData(true);

        $this->assertSame([['id' => 3], ['id' => 4]], $body['data']);
        $this->assertSame(['page' => 2, 'per_page' => 2, 'total' => 5, 'last_page' => 3], $body['meta']['pagination']);
        $this->assertSame(['stale' => false], $body['meta']['freshness']);
    }

    public function test_paginated_array_slices_in_memory_lists(): void
    {
        $items = array_map(fn ($i) => ['id' => $i], range(1, 7));

        $body = ApiResponse::paginatedArray($items, 2, 3, JsonResource::class)->getData(true);

        $this->assertSame([['id' => 4], ['id' => 5], ['id' => 6]], $body['data']);
        $this->assertSame(['page' => 2, 'per_page' => 3, 'total' => 7, 'last_page' => 3], $body['meta']['pagination']);
    }

    public function test_error_codes_for_status(): void
    {
        $this->assertSame('unauthenticated', ApiResponse::codeForStatus(401));
        $this->assertSame('forbidden', ApiResponse::codeForStatus(403));
        $this->assertSame('not_found', ApiResponse::codeForStatus(404));
        $this->assertSame('validation_failed', ApiResponse::codeForStatus(422));
        $this->assertSame('server_error', ApiResponse::codeForStatus(502));
        $this->assertSame('http_error', ApiResponse::codeForStatus(418));
    }
}
