<?php

namespace App\Console\Commands;

use App\Models\DiscordBotSetting;
use App\Services\DiscordBotService;
use Illuminate\Console\Command;

class DiscordBot extends Command
{
    protected $signature = 'discord:bot';

    protected $description = 'Start the Discord welcome bot (work in progress; off unless config/features.php enables it)';

    public function handle(): int
    {
        if (! config('features.discord_bot')) {
            $this->error('The Discord welcome bot is a work in progress and is turned off in this build (config/features.php).');

            return self::FAILURE;
        }

        $settings = DiscordBotSetting::getSettings();

        if (! $settings->is_enabled) {
            $this->error('The Discord bot is disabled. Enable it in Admin → Discord Bot settings.');

            return self::FAILURE;
        }

        if (! $settings->bot_token) {
            $this->error('No bot token configured. Set the bot token in Admin → Discord Bot settings.');

            return self::FAILURE;
        }

        if (! $settings->welcome_channel_id) {
            $this->warn('No welcome channel ID configured. Welcome messages will not be sent.');
        }

        $this->info('Starting Discord welcome bot…');
        $this->info("Bot name: {$settings->bot_name}");

        $service = new DiscordBotService($settings);

        // Apply the configured name/avatar to the bot profile before connecting
        try {
            $service->updateBotProfile();
            $this->info('Bot profile updated.');
        } catch (\Throwable $e) {
            $this->warn('Could not update bot profile: '.$e->getMessage());
        }

        $service->run($this);

        return self::SUCCESS;
    }
}
