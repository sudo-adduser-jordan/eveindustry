<?php

namespace App\Services\Reference;

use App\Services\EsiService;

/**
 * Single inventory type lookup: local SDE row first, ESI /universe/types as
 * a fallback. Shared by the old .../type-info routes and /api/v1/types/{id}.
 */
class TypeLookupService
{
    public function __construct(private EsiService $esi) {}

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $typeId): ?array
    {
        return $this->esi->getTypeInfo($typeId);
    }
}
