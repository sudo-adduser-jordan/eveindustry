<?php

namespace App\Services\Planning;

use App\Models\User;
use App\Models\WholemapSignature;
use App\Models\WholemapSystemNote;
use App\Models\WormholeConnection;
use App\Models\WormholeJumpLog;

/**
 * Writes for the wormhole mapper (wholemap): signatures, connections, system
 * notes and jump logs. Shared by the legacy /api/wholemap routes and /api/v1.
 *
 * Callers check ownership (policies on v1, inline checks on the legacy
 * routes) and validate input; this class only writes.
 */
class WholemapWriter
{
    private const PASTE_TYPES = [
        'combat site' => 'combat',
        'data site' => 'data',
        'relic site' => 'relic',
        'ore site' => 'ore',
        'gas site' => 'gas',
        'wormhole' => 'wormhole',
        'ghost site' => 'ghost',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function createSignature(User $user, array $data): WholemapSignature
    {
        $data['user_id'] = $user->id;
        $data['created_by'] = $user->name;
        $data['updated_by'] = $user->name;

        return WholemapSignature::create($data)->load('wormholeConnection');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateSignature(User $user, WholemapSignature $signature, array $data): WholemapSignature
    {
        $data['updated_by'] = $user->name;
        $signature->update($data);

        return $signature->fresh()->load('wormholeConnection');
    }

    public function toggleLock(WholemapSignature $signature): WholemapSignature
    {
        $signature->update(['locked' => ! $signature->locked]);

        return $signature;
    }

    public function deleteSignature(WholemapSignature $signature): void
    {
        $signature->delete();
    }

    /**
     * Import a pasted probe scanner list. Existing signatures are updated
     * unless locked (a locked one is skipped and not counted).
     *
     * @return array{created: int, updated: int}
     */
    public function pasteSignatures(User $user, int $systemId, string $systemName, string $paste): array
    {
        $created = 0;
        $updated = 0;

        foreach (preg_split('/\r?\n/', trim($paste)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $cols = explode("\t", $line);
            if (count($cols) < 3) {
                continue;
            }

            $sigId = trim($cols[0]);
            $typeName = strtolower(trim($cols[2] ?? ''));
            $name = trim($cols[3] ?? '');

            // EVE signature IDs are 3 letters + dash + 3 digits, max 7 chars
            if ($sigId === '' || strlen($sigId) > 7) {
                continue;
            }

            $type = self::PASTE_TYPES[$typeName] ?? 'unknown';

            $existing = WholemapSignature::where('user_id', $user->id)
                ->where('system_id', $systemId)
                ->where('signature_id', $sigId)
                ->first();

            if ($existing) {
                if (! $existing->locked) {
                    $updateData = ['updated_by' => $user->name];
                    if ($type !== 'unknown') {
                        $updateData['type'] = $type;
                    }
                    if ($name !== '') {
                        $updateData['name'] = substr($name, 0, 100);
                    }
                    $existing->update($updateData);
                    $updated++;
                }
            } else {
                WholemapSignature::create([
                    'user_id' => $user->id,
                    'signature_id' => $sigId,
                    'system_id' => $systemId,
                    'system_name' => $systemName,
                    'type' => $type,
                    'name' => $name !== '' ? substr($name, 0, 100) : null,
                    'created_by' => $user->name,
                    'updated_by' => $user->name,
                ]);
                $created++;
            }
        }

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createConnection(User $user, array $data): WormholeConnection
    {
        $data['user_id'] = $user->id;

        return WormholeConnection::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateConnection(WormholeConnection $connection, array $data): WormholeConnection
    {
        $connection->update($data);

        return $connection->fresh();
    }

    public function deleteConnection(WormholeConnection $connection): void
    {
        $connection->delete();
    }

    /**
     * Create or update the user's note for a system (one note per system).
     *
     * @param  array<string, mixed>  $data
     */
    public function upsertNote(User $user, array $data): WholemapSystemNote
    {
        $data['user_id'] = $user->id;

        return WholemapSystemNote::updateOrCreate(
            ['user_id' => $data['user_id'], 'system_id' => $data['system_id']],
            $data,
        );
    }

    public function deleteNote(WholemapSystemNote $note): void
    {
        $note->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function logJump(User $user, array $data): WormholeJumpLog
    {
        $data['user_id'] = $user->id;
        $data['jumped_at'] = now();

        return WormholeJumpLog::create($data);
    }
}
