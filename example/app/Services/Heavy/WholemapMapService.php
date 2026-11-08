<?php

namespace App\Services\Heavy;

use App\Models\WholemapSignature;
use App\Models\WholemapSystemNote;
use App\Models\WormholeConnection;
use Illuminate\Support\Collection;

/**
 * What the wormhole map page shows: the user's signatures, connections and
 * system notes from the last 24 hours. Mapped data expires after a day, as
 * wormholes do.
 */
class WholemapMapService
{
    public const EXPIRY_HOURS = 24;

    /**
     * @return array{signatures: Collection<int, WholemapSignature>, connections: Collection<int, WormholeConnection>, notes: Collection<int, WholemapSystemNote>, systems: Collection<int, string>}
     */
    public function forUser(int $userId, ?int $systemId = null): array
    {
        $cutoff = now()->subHours(self::EXPIRY_HOURS);

        $signatures = WholemapSignature::where('user_id', $userId)
            ->where('created_at', '>=', $cutoff)
            ->when($systemId, fn ($q) => $q->where('system_id', $systemId))
            ->with('wormholeConnection')
            ->orderByDesc('updated_at')
            ->get();

        // Connections are not narrowed to the system: the chain is the whole map.
        $connections = WormholeConnection::where('user_id', $userId)
            ->where('created_at', '>=', $cutoff)
            ->orderByDesc('updated_at')
            ->get();

        $notes = WholemapSystemNote::where('user_id', $userId)
            ->where('created_at', '>=', $cutoff)
            ->when($systemId, fn ($q) => $q->where('system_id', $systemId))
            ->get();

        $systems = $signatures->pluck('system_name', 'system_id')
            ->merge($connections->pluck('from_system_name', 'from_system_id'))
            ->merge($connections->filter(fn ($c) => $c->to_system_id)->pluck('to_system_name', 'to_system_id'))
            ->unique();

        return compact('signatures', 'connections', 'notes', 'systems');
    }
}
