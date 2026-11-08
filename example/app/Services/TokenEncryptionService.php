<?php

namespace App\Services;

use App\Casts\EsiToken;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class TokenEncryptionService
{
    /** Encrypt existing rows under short row locks, without overwriting rotation. */
    public function backfill(bool $write): int
    {
        $changed = 0;
        DB::table('characters')->select('character_id')->orderBy('character_id')
            ->chunkById(100, function ($rows) use ($write, &$changed) {
                foreach ($rows as $row) {
                    $changed += DB::transaction(function () use ($row, $write) {
                        $record = DB::table('characters')->where('character_id', $row->character_id)->lockForUpdate()->first();
                        if (! $record) {
                            return 0;
                        }
                        $updates = [];
                        foreach (['access_token', 'refresh_token'] as $column) {
                            $token = $record->{$column};
                            if ($token !== null && ! EsiToken::encrypted($token)) {
                                $updates[$column] = $write ? Crypt::encryptString($token) : '';
                            }
                        }
                        if ($write && $updates !== []) {
                            DB::table('characters')->where('character_id', $row->character_id)->update($updates);
                        }

                        return $updates === [] ? 0 : 1;
                    }, 3);
                }
            }, 'character_id');

        return $changed;
    }
}
