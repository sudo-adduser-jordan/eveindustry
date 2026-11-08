<?php

namespace App\Http\Responses;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The /api/v1 response envelope.
 *
 * Success: {"data": ..., "meta": {...}}
 *   meta.pagination = {page, per_page, total, last_page} on paginated lists
 *   meta.freshness  = {prices_as_of, costs_as_of, source, stale} on price/cost data
 *
 * Error: Laravel's {"message": ..., "errors"?: {...}} plus a machine-readable
 * "code" (see codeForStatus()). Errors are shaped in bootstrap/app.php.
 */
class ApiResponse
{
    public const ERROR_CODES = [
        400 => 'bad_request',
        401 => 'unauthenticated',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        409 => 'conflict',
        419 => 'csrf_token_mismatch',
        422 => 'validation_failed',
        429 => 'too_many_requests',
        500 => 'server_error',
        503 => 'service_unavailable',
    ];

    /**
     * @param  JsonResource|array<mixed>|null  $data
     * @param  array<string, mixed>  $meta
     */
    public static function success(JsonResource|array|null $data, array $meta = [], int $status = 200): JsonResponse
    {
        if ($data instanceof JsonResource) {
            $data = $data->resolve(request());
        }

        return new JsonResponse(['data' => $data, 'meta' => (object) $meta], $status);
    }

    /**
     * @param  class-string<JsonResource>  $resource
     * @param  array<string, mixed>  $meta
     */
    public static function paginated(LengthAwarePaginator $paginator, string $resource, array $meta = []): JsonResponse
    {
        return self::success(
            $resource::collection($paginator->getCollection()),
            array_merge($meta, ['pagination' => self::pagination($paginator)]),
        );
    }

    /**
     * Paginate a list that is already in memory (e.g. a service result) and
     * wrap it like paginated().
     *
     * @param  array<int, mixed>  $items
     * @param  class-string<JsonResource>  $resource
     * @param  array<string, mixed>  $meta
     */
    public static function paginatedArray(array $items, int $page, int $perPage, string $resource, array $meta = []): JsonResponse
    {
        $items = array_values($items);
        $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
            array_slice($items, ($page - 1) * $perPage, $perPage),
            count($items),
            $perPage,
            $page,
        );

        return self::paginated($paginator, $resource, $meta);
    }

    /**
     * @return array{page: int, per_page: int, total: int, last_page: int}
     */
    public static function pagination(LengthAwarePaginator $paginator): array
    {
        return [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    public static function codeForStatus(int $status): string
    {
        return self::ERROR_CODES[$status] ?? ($status >= 500 ? 'server_error' : 'http_error');
    }
}
