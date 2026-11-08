<?php

namespace App\Services\Esi;

use App\Models\Character;
use App\Services\EsiRateLimiter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EsiAuthClient
{
    public function revokeToken(string $token): void
    {
        try {
            $this->request()->post('https://login.eveonline.com/v2/oauth/revoke', [
                'token' => $token,
                'token_type_hint' => 'refresh_token',
                'client_id' => config('services.eveonline.client_id'),
                'client_secret' => config('services.eveonline.client_secret'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('SSO revocation transport failed');
        }
    }

    protected function request(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::asForm()->connectTimeout(config('esi.connect_timeout', 5))->timeout(config('esi.timeout', 30))->withUserAgent(config('esi.user_agent'));
    }

    public function refreshToken(string $refreshToken): ?array
    {
        try {
            return $this->refreshTokenOrFail($refreshToken);
        } catch (EsiRequestException $e) {
            return null;
        }
    }

    protected function refreshTokenOrFail(string $refreshToken): array
    {
        try {
            $response = new EsiResponse($this->request()->post('https://login.eveonline.com/v2/oauth/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => config('services.eveonline.client_id'),
                'client_secret' => config('services.eveonline.client_secret'),
            ]));
        } catch (\Throwable $e) {
            throw new EsiRequestException('transient', 60);
        }
        $data = $response->data();
        if (! $response->successful()) {
            $permanent = $response->status() === 400 && ($data['error'] ?? null) === 'invalid_grant';
            Log::warning('SSO refresh rejected', ['status' => $response->status(), 'reason' => $permanent ? 'invalid_grant' : 'transient']);
            throw new EsiRequestException($permanent ? 'credential' : 'transient', $permanent ? 0 : max(60, $response->retryAfterSeconds()), $response);
        }
        if (! is_string($data['access_token'] ?? null) || $data['access_token'] === '') {
            throw new EsiRequestException('transient', 60, $response);
        }

        return $data;
    }

    public function refreshCharacterToken(Character $character, bool $force = false): bool
    {
        try {
            return $this->refreshCharacterTokenOrFail($character, $force);
        } catch (EsiRequestException $e) {
            return false;
        }
    }

    public function refreshCharacterTokenOrFail(Character $character, bool $force = false): bool
    {
        $priorAccessToken = $character->access_token;
        try {
            $store = app(EsiRateLimiter::class)->store();

            return $store->lock('esi.refresh.'.$character->character_id, 45)->block(5, function () use ($character, $force, $priorAccessToken, $store) {
                $character->refresh();
                // A waiting worker must use the winner's rotated credential.
                if ($character->access_token !== $priorAccessToken || (! $force && $character->token_expires_at?->greaterThan(now()->addSeconds(30)))) {
                    return true;
                }
                if (! $character->refresh_token) {
                    throw new EsiRequestException('credential');
                }
                $cooldownKey = 'esi.sso.until.'.$character->character_id;
                $wait = (int) $store->get($cooldownKey, 0) - now()->timestamp;
                if ($wait > 0) {
                    throw new EsiRequestException('transient', $wait);
                }
                try {
                    $tokenData = $this->refreshTokenOrFail($character->refresh_token);
                } catch (EsiRequestException $e) {
                    if ($e->outcome === 'credential') {
                        $character->update(['is_esi_active' => false]);
                    } else {
                        $delay = max(1, $e->retryAfterSeconds);
                        $store->put($cooldownKey, now()->timestamp + $delay, $delay);
                    }
                    throw $e;
                }
                $character->update([
                    'access_token' => $tokenData['access_token'],
                    'refresh_token' => $tokenData['refresh_token'] ?? $character->refresh_token,
                    'token_expires_at' => now()->addSeconds((int) ($tokenData['expires_in'] ?? 1199)),
                ]);

                return true;
            });
        } catch (EsiRequestException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new EsiRequestException('coordination', 5);
        }
    }
}
