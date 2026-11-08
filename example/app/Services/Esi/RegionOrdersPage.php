<?php

namespace App\Services\Esi;

/**
 * Result of fetching one page of GET /markets/{region_id}/orders/.
 *
 * Statuses:
 *  - ok:            200, $orders holds the page.
 *  - not_modified:  304 for the ETag sent; the page is unchanged.
 *  - missing:       404 for a page past the end (the page count shrank
 *                   between ESI snapshots); treated as an empty page.
 *  - rate_limited:  local/shared cooldown, coordination deferral, or an
 *                   upstream 420/429. Retry after $retryAfter seconds.
 *  - failed:        any other HTTP status, or a transport error.
 */
final class RegionOrdersPage
{
    public const OK = 'ok';

    public const NOT_MODIFIED = 'not_modified';

    public const MISSING = 'missing';

    public const RATE_LIMITED = 'rate_limited';

    public const FAILED = 'failed';

    /**
     * @param  list<array<string, mixed>>  $orders
     */
    public function __construct(
        public readonly string $status,
        public readonly array $orders = [],
        public readonly ?int $pages = null,
        public readonly ?string $etag = null,
        public readonly ?int $httpStatus = null,
        public readonly int $retryAfter = 0,
    ) {}

    public function isSuccessful(): bool
    {
        return in_array($this->status, [self::OK, self::NOT_MODIFIED, self::MISSING], true);
    }
}
