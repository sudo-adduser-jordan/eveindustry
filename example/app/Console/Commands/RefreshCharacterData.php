<?php

namespace App\Console\Commands;

use App\Jobs\RefreshCharacterProfile;
use App\Models\Character;
use Illuminate\Console\Command;

class RefreshCharacterData extends Command
{
    protected $signature = 'characters:refresh-data';

    protected $description = 'Queue staggered profile refreshes for recently active characters';

    public function handle(): int
    {
        $query = Character::where('is_esi_active', true)->whereHas('user', fn ($q) => $q
            ->where('is_active', true)->where('last_login_at', '>=', now()->subDays(7)));
        $total = (clone $query)->count();
        $index = 0;
        $query->select('character_id')->chunkById(200, function ($characters) use (&$index, $total) {
            foreach ($characters as $character) {
                RefreshCharacterProfile::dispatch($character->character_id)
                    ->delay(now()->addSeconds((int) floor($index++ * 600 / max(1, $total))));
            }
        }, 'character_id');
        $this->info("Queued profile refreshes for {$total} eligible characters.");

        return self::SUCCESS;
    }
}
