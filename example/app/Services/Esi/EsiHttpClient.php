<?php

namespace App\Services\Esi;

use App\Models\Character;
use App\Services\Demo\DemoEsiResponder;
use App\Services\Demo\DemoIds;
use App\Services\Esi\Diagnostics\EsiDiagnosticsContext;
use App\Services\Esi\Diagnostics\EsiDiagnosticsRecorder;
use App\Services\EsiRateLimiter;
use App\Services\EsiService;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Low-level ESI transport. Legacy wrappers retain their array/null API. */
class EsiHttpClient
{
    public const ESI_PAGE_SIZE = 1000;

    protected string $baseUrl = 'https://esi.evetech.net/latest';

    protected array $paginationMetadata = [];

    public function __construct(protected EsiRateLimiter $rateLimiter, protected EsiAuthClient $auth) {}

    public function trackEsiErrorLimit(Response $response, ?int $characterId = null): void
    {
        $remain = $response->header('X-Esi-Error-Limit-Remain');
        $delay = max(1, (int) ($response->header('X-Esi-Error-Limit-Reset') ?: EsiService::ESI_ERROR_DEFAULT_RESET_SECONDS));
        if ($response->status() === 420) {
            $delay = max($delay, (new EsiResponse($response))->retryAfterSeconds());
        }
        $store = $this->rateLimiter->store();
        if ($remain !== null && $remain !== '') {
            $suffix = $characterId === null ? '' : '.'.$characterId;
            $store->put('esi.error_limit_remain'.$suffix, (int) $remain, $delay);
            $store->put('esi.error_limit_reset'.$suffix, now()->timestamp + $delay, $delay);
        }
        // Legacy 420 covers routes across identities; use a conservative shared
        // circuit until the actual upstream error identity is established.
        if ($response->status() === 420 || ($remain !== null && $remain !== '' && (int) $remain <= EsiService::ESI_ERROR_LIMIT_THRESHOLD)) {
            $store->lock('esi.error_circuit.lock', 10)->block(2, function () use ($store, $delay, $remain) {
                $previous = (int) $store->get('esi.error_circuit.until', 0);
                $deadline = max($previous, now()->timestamp + $delay);
                $store->put('esi.error_circuit.until', $deadline, max(1, $deadline - now()->timestamp));
                if ($previous <= now()->timestamp && $this->diagnostics()->enabled()) {
                    $store->put('esi.error_circuit.diag_opened_at', now()->timestamp, max(1, $deadline - now()->timestamp) + 3600);
                    $this->diagnostics()->circuit('circuit_opened', 'error_limited', [
                        'legacy_remaining' => $remain,
                        'legacy_reset_seconds' => $deadline - now()->timestamp,
                        'legacy_reset_at' => $deadline,
                    ]);
                }
            });
        }
    }

    public function isEsiErrorLimited(?int $characterId = null): bool
    {
        return $this->esiErrorResetSeconds($characterId) > 0;
    }

    public function esiErrorResetSeconds(?int $characterId = null): int
    {
        try {
            $remaining = max(0, (int) $this->rateLimiter->store()->get('esi.error_circuit.until', 0) - now()->timestamp);
            if ($remaining === 0 && $this->diagnostics()->enabled()) {
                $this->recordInferredCircuitClose();
            }

            return $remaining;
        } catch (\Throwable $e) {
            throw new EsiRequestException('coordination', 5);
        }
    }

    protected function diagnostics(): EsiDiagnosticsRecorder
    {
        return app(EsiDiagnosticsRecorder::class);
    }

    /** The circuit expires on its own; the first observer after expiry records the end, labelled inferred. */
    protected function recordInferredCircuitClose(): void
    {
        try {
            $openedAt = $this->rateLimiter->store()->pull('esi.error_circuit.diag_opened_at');
            if ($openedAt !== null) {
                $this->diagnostics()->circuit('circuit_closed', 'circuit_expired_inferred', ['duration_ms' => max(0, now()->timestamp - (int) $openedAt) * 1000]);
            }
        } catch (\Throwable $e) {
            // Diagnostics must never affect coordination.
        }
    }

    protected function defer(string $reason, int $delay, string $method, string $endpoint, ?Character $character): EsiRequestException
    {
        $this->diagnostics()->localDeferral($method, $endpoint, $character, $reason);

        return new EsiRequestException($reason, $delay);
    }

    public function resolveRateLimitGroup(string $endpoint, string $method = 'get'): string
    {
        $path = parse_url($endpoint, PHP_URL_PATH) ?? $endpoint;
        $path = preg_replace('#^/(latest|legacy|dev|v\d+)(?=/)#', '', $path);
        $path = rtrim($path, '/');
        $learned = $this->rateLimiter->store()->get('esi.routegroup.'.hash('sha256', strtolower($method).' '.$path));
        if ($learned !== null) {
            return $learned;
        }
        foreach (config('esi_routes', []) as $route => $group) {
            [$routeMethod, $template] = explode(' ', $route, 2);
            if ($routeMethod !== strtolower($method)) {
                continue;
            }
            $pattern = preg_replace('/\\\{[^}]+\\\}/', '[^/]+', preg_quote($template, '#'));
            if (preg_match('#^'.$pattern.'$#', $path)) {
                return $group;
            }
        }

        return 'legacy';
    }

    public function publicRequest(string $method, string $endpoint, ?array $params = null, array $headers = []): ?Response
    {
        if ($demo = $this->demoPublic($method, $endpoint, $params)) {
            return $demo->response;
        }

        try {
            return $this->cached($method, $endpoint, null, $params, $headers, fn ($conditional) => $this->send($method, $endpoint, null, $params, $conditional))->response;
        } catch (EsiRequestException $e) {
            // Public callers historically inspect status themselves.
            return $e->response?->response;
        }
    }

    public function publicResponse(string $method, string $endpoint, ?array $params = null, array $headers = []): EsiResponse
    {
        if ($demo = $this->demoPublic($method, $endpoint, $params)) {
            return $this->requireSuccess($demo);
        }

        return $this->requireSuccess($this->cached($method, $endpoint, null, $params, $headers, fn ($conditional) => $this->send($method, $endpoint, null, $params, $conditional)));
    }

    public function authenticatedRequest(string $method, string $endpoint, Character $character, bool $retried = false): ?array
    {
        try {
            return $this->authenticatedResponse($method, $endpoint, $character, $retried)->data();
        } catch (EsiRequestException $e) {
            return null;
        }
    }

    public function authenticatedResponse(string $method, string $endpoint, Character $character, bool $retried = false, ?array $params = null, array $headers = []): EsiResponse
    {
        // Queued models and relation collections can outlive an unlink, grant
        // change or permanent credential rejection. Check before cache reuse.
        if ($character->exists) {
            $latest = $character->newQuery()->find($character->getKey());
            if ($latest === null || $latest->is_esi_active === false) {
                throw new EsiRequestException('credential');
            }
            $character->setRawAttributes($latest->getAttributes(), true);
        }

        // Demo characters never reach ESI: payloads are generated locally.
        if (DemoIds::isDemo((int) $character->character_id)) {
            $demo = app(DemoEsiResponder::class)->respond(strtolower($method), $endpoint, $character, $params);

            return $demo ? $this->requireSuccess($demo) : throw new EsiRequestException('not_found');
        }

        return $this->cached($method, $endpoint, $character, $params, $headers, fn ($conditional) => $this->authenticatedUncachedResponse($method, $endpoint, $character, $retried, $params, $conditional));
    }

    /** Public lookups of demo characters, corporations and planets are answered locally. */
    protected function demoPublic(string $method, string $endpoint, ?array $params): ?EsiResponse
    {
        $method = strtolower($method);
        $affiliation = $method === 'post' && str_starts_with($endpoint, '/characters/affiliation') && collect($params)->contains(fn ($id) => DemoIds::isDemo((int) $id));
        if (! $affiliation && ! DemoEsiResponder::handles($endpoint)) {
            return null;
        }

        return app(DemoEsiResponder::class)->respond($method, $endpoint, null, $params);
    }

    protected function cached(string $method, string $endpoint, ?Character $character, ?array $params, array $headers, callable $fetch): EsiResponse
    {
        try {
            return strtolower($method) === 'get'
                ? app(EsiHttpCache::class)->remember($endpoint, $character, $params, $headers, $fetch)
                : $fetch($headers);
        } catch (EsiRequestException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new EsiRequestException('coordination', 5);
        }
    }

    protected function authenticatedUncachedResponse(string $method, string $endpoint, Character $character, bool $retried, ?array $params, array $headers): EsiResponse
    {
        if ($this->isEsiErrorLimited()) {
            throw $this->defer('error_limited', max(1, $this->esiErrorResetSeconds()), $method, $endpoint, $character);
        }
        if (! $character->token_expires_at || $character->token_expires_at->lessThanOrEqualTo(now()->addSeconds(30))) {
            if (! $this->auth->refreshCharacterTokenOrFail($character)) {
                throw new EsiRequestException('transient', 60);
            }
        }
        $response = $this->send($method, $endpoint, $character, $params, $headers);
        if ($response->status() === 401 && ! $retried && ! $this->isMissingScope($response)) {
            if ($this->auth->refreshCharacterTokenOrFail($character, true)) {
                return $this->authenticatedUncachedResponse($method, $endpoint, $character, true, $params, $headers);
            }
            throw new EsiRequestException('transient', 60, $response);
        }

        return $this->requireSuccess($response);
    }

    protected function send(string $method, string $endpoint, ?Character $character, ?array $params, array $headers): EsiResponse
    {
        $startedAt = null;
        try {
            if ($this->isEsiErrorLimited()) {
                throw $this->defer('error_limited', max(1, $this->esiErrorResetSeconds()), $method, $endpoint, $character);
            }
            $group = $this->resolveRateLimitGroup($endpoint, $method);
            $identity = $character ? (string) $character->character_id : null;
            if ($group === 'legacy' && $this->rateLimiter->isLimited($group, $identity)) {
                throw $this->defer('rate_limited', max(1, $this->rateLimiter->resetsInSeconds($group, $identity)), $method, $endpoint, $character);
            }
            // Routes without x-rate-limit still use the shared legacy error
            // circuit, but must not inherit an invented token budget.
            $reservation = $group === 'legacy' ? null : $this->rateLimiter->reserve($group, $identity);
            if ($group !== 'legacy' && $reservation === null) {
                throw $this->defer('rate_limited', max(1, $this->rateLimiter->resetsInSeconds($group, $identity, 5)), $method, $endpoint, $character);
            }
            $defaults = [
                'User-Agent' => trim((string) config('esi.user_agent')) ?: 'EveIndustrialist/1.0',
                'X-Compatibility-Date' => config('esi.compatibility_date'),
            ];
            if ($character) {
                $defaults['Authorization'] = 'Bearer '.$character->access_token;
            }
            $http = Http::connectTimeout(config('esi.connect_timeout', 5))->timeout(config('esi.timeout', 30))->withHeaders(array_merge($headers, $defaults));
            $url = str_starts_with($endpoint, 'http') ? $endpoint : $this->baseUrl.$endpoint;
            $startedAt = hrtime(true);
            $response = $params === null ? $http->{strtolower($method)}($url) : $http->{strtolower($method)}($url, $params);
            // One event per wire attempt, taken before budget handling or classification can throw.
            $this->diagnostics()->httpAttempt($method, $endpoint, $character, $response, (int) ((hrtime(true) - $startedAt) / 1_000_000), $group);
            $this->trackEsiErrorLimit($response, $character?->character_id);
            if ($reservation !== null) {
                $this->rateLimiter->reconcile($group, $identity, $reservation, $response);
            }
            $remoteGroup = $response->header('X-Ratelimit-Group');
            if ($remoteGroup && $remoteGroup !== $group && preg_match('/^[a-z0-9-]+$/', $remoteGroup)) {
                $path = preg_replace('#^/(latest|legacy|dev|v\d+)(?=/)#', '', parse_url($endpoint, PHP_URL_PATH) ?? $endpoint);
                $this->rateLimiter->store()->put('esi.routegroup.'.hash('sha256', strtolower($method).' '.rtrim($path, '/')), $remoteGroup, 86400);
                $this->rateLimiter->reconcile($remoteGroup, $identity, '', $response);
            } elseif ($group === 'legacy' && $response->status() === 429) {
                // Legacy server throttles do not always advertise a group.
                $this->rateLimiter->reconcile('legacy', $identity, '', $response);
            }

            return new EsiResponse($response);
        } catch (EsiRequestException $e) {
            throw $e;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            if ($startedAt !== null) {
                $this->diagnostics()->connectionFailure($method, $endpoint, $character, (int) ((hrtime(true) - $startedAt) / 1_000_000));
            }
            throw new EsiRequestException('transient', 30);
        } catch (\Throwable $e) {
            throw new EsiRequestException('coordination', 5);
        }
    }

    protected function isMissingScope(EsiResponse $response): bool
    {
        $message = strtolower((string) ($response->data()['error'] ?? '').' '.($response->header('WWW-Authenticate') ?? ''));

        return str_contains($message, 'insufficient_scope') || str_contains($message, 'missing scope') || str_contains($message, 'missing required scope') || str_contains($message, 'not have the required scope') || str_contains($message, 'not valid for any required scope');
    }

    protected function requireSuccess(EsiResponse $response): EsiResponse
    {
        if ($response->successful() || $response->status() === 304) {
            return $response;
        }
        $outcome = match (true) {
            $response->status() === 420 => 'error_limited',
            $response->status() === 429 => 'rate_limited',
            $this->isMissingScope($response) => 'missing_scope',
            $response->status() === 401 => 'credential',
            $response->status() === 403 => 'forbidden',
            $response->status() === 404 => 'not_found',
            $response->status() >= 500 => 'transient',
            default => 'invalid_response',
        };
        $delay = match ($outcome) {
            'error_limited' => max(1, $this->esiErrorResetSeconds()),
            'rate_limited', 'transient' => $response->header('Retry-After') !== '' && $response->header('Retry-After') !== null ? max(1, $response->retryAfterSeconds()) : 60,
            default => 0,
        };
        throw new EsiRequestException($outcome, $delay, $response);
    }

    /** Return only a complete list; pagination failures can never publish a prefix. */
    public function authenticatedPaginatedRequest(string $endpoint, Character $character): array
    {
        $all = [];
        $expectedPages = null;
        $lastModified = null;
        $metadata = [];
        $limit = (int) config('esi.pagination_safety_limit', 10000);
        for ($page = 1; $page <= $limit; $page++) {
            $sep = str_contains($endpoint, '?') ? '&' : '?';
            $response = app(EsiDiagnosticsContext::class)->with(['page' => $page], fn () => $this->authenticatedResponse('get', $endpoint.$sep.'page='.$page, $character));
            $data = $response->data();
            if (! $response->successful() || $data === null || ! array_is_list($data)) {
                throw new EsiRequestException('incomplete', 30, $response);
            }
            $pages = $response->header('X-Pages');
            if ($pages !== null && $pages !== '') {
                if (! ctype_digit($pages) || (int) $pages < 1 || (int) $pages > $limit || ($expectedPages !== null && $expectedPages !== (int) $pages)) {
                    throw new EsiRequestException('incomplete', 30, $response);
                }
                $expectedPages = (int) $pages;
            }
            $modified = $response->header('Last-Modified');
            if ($page === 1) {
                $lastModified = $modified;
            } elseif ($lastModified !== null && $modified !== null && $modified !== $lastModified) {
                throw new EsiRequestException('incomplete', 30, $response);
            }
            $all = array_merge($all, $data);
            $metadata[] = app(EsiHttpCache::class)->responseMetadata($response);
            if ($expectedPages !== null ? $page >= $expectedPages : count($data) < self::ESI_PAGE_SIZE) {
                $expiries = array_column($metadata, 'source_expires_at');
                $this->paginationMetadata[$endpoint.'|'.$character->character_id] = [
                    'source_updated_at' => min(array_column($metadata, 'source_updated_at')),
                    'validated_at' => min(array_column($metadata, 'validated_at')),
                    'source_expires_at' => in_array(null, $expiries, true) ? null : min($expiries),
                ];

                return $all;
            }
        }
        throw new EsiRequestException('incomplete', 30);
    }

    public function paginationMetadata(string $endpoint, Character $character): ?array
    {
        return $this->paginationMetadata[$endpoint.'|'.$character->character_id] ?? null;
    }

    public function rateLimiter(): EsiRateLimiter
    {
        return $this->rateLimiter;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }
}
