<?php

namespace App\Console\Commands;

use App\Services\TokenEncryptionService;
use Illuminate\Console\Command;

class EncryptEsiTokens extends Command
{
    protected $signature = 'esi:encrypt-tokens {--write : Encrypt legacy stored credentials; default is a dry run}';

    protected $description = 'Count or encrypt legacy ESI credentials without exposing their values';

    public function handle(TokenEncryptionService $encryption): int
    {
        $write = (bool) $this->option('write');
        if ($write && ! config('esi.encrypt_tokens', false)) {
            $this->error('Enable ESI_ENCRYPT_TOKENS after deploying compatible readers to every web and queue worker.');

            return self::FAILURE;
        }
        $count = $encryption->backfill($write);
        $this->info($write ? "Encrypted credentials for {$count} character(s)." : "Dry run: {$count} character(s) have legacy credentials. No credentials changed.");

        return self::SUCCESS;
    }
}
