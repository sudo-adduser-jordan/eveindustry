<?php

namespace App\Console\Commands;

use App\Models\Character;
use App\Services\Demo\DemoIds;
use App\Services\EsiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RefreshExpiringTokens extends Command
{
    protected $signature = 'esi:refresh-tokens';

    protected $description = 'Refresh ESI character tokens that are expiring soon';

    public function __construct(protected EsiService $esiService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $threshold = now()->addMinutes(10);

        $characters = Character::whereNotNull('refresh_token')
            ->whereNotBetween('character_id', [DemoIds::CHARACTER_BASE, DemoIds::CEILING])
            ->where(function ($query) use ($threshold) {
                $query->whereNull('token_expires_at')
                    ->orWhere('token_expires_at', '<=', $threshold);
            })
            ->get();

        $this->info("Found {$characters->count()} characters with expiring tokens.");

        $refreshed = 0;
        $failed = 0;

        foreach ($characters as $character) {
            try {
                if ($this->esiService->refreshCharacterToken($character)) {
                    $refreshed++;
                    $this->line("  ✓ {$character->name} (ID: {$character->character_id})");
                } else {
                    $failed++;
                    $character->update(['is_esi_active' => false]);
                    $this->warn("  ✗ {$character->name} (ID: {$character->character_id}): refresh failed — deactivated");
                    Log::warning("ESI: Token refresh failed for character {$character->character_id} — deactivated from automated tasks");
                }
            } catch (\Exception $e) {
                $failed++;
                $character->update(['is_esi_active' => false]);
                Log::error("Failed to refresh token for character {$character->character_id}: {$e->getMessage()} — deactivated from automated tasks");
                $this->warn("  ✗ {$character->name} (ID: {$character->character_id}): {$e->getMessage()} — deactivated");
            }
        }

        $this->info("Token refresh complete: {$refreshed} refreshed, {$failed} failed.");
        Log::info('ESI token refresh complete', ['refreshed' => $refreshed, 'failed' => $failed, 'total' => $characters->count()]);

        return self::SUCCESS;
    }
}
