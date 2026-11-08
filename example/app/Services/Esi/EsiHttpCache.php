<?php

namespace App\Services\Esi;

use App\Models\Character;
use App\Services\Esi\Diagnostics\EsiDiagnosticsRecorder;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;

/** Stores each complete HTTP representation and its validators in one envelope. */
class EsiHttpCache
{
    public function remember(string $endpoint, ?Character $character, ?array $params, array $headers, callable $fetch): EsiResponse
    {
        $key = $this->key($endpoint, $character, $params, $headers);
        $store = Cache::store(config('esi.http_cache_store') ?: config('cache.default'));
        $cached = $store->get($key);
        if ($this->valid($cached) && $cached['fresh_until'] > now()->timestamp) {
            app(EsiDiagnosticsRecorder::class)->cache('cache_hit', $endpoint, $character);

            return $this->response($cached);
        }
        try {
            return $store->lock('lock.'.$key, 45)->block(2, function () use ($store, $key, $headers, $fetch, $endpoint, $character) {
                $cached = $store->get($key);
                if ($this->valid($cached) && $cached['fresh_until'] > now()->timestamp) {
                    return $this->response($cached);
                }
                $conditional = $headers;
                app(EsiDiagnosticsRecorder::class)->cache($this->valid($cached) ? 'cache_revalidation' : 'cache_miss', $endpoint, $character);
                if ($this->valid($cached)) {
                    $stored = $this->response($cached);
                    if ($stored->header('ETag')) {
                        $conditional['If-None-Match'] = $stored->header('ETag');
                    } elseif ($stored->header('Last-Modified')) {
                        $conditional['If-Modified-Since'] = $stored->header('Last-Modified');
                    }
                }
                $response = $fetch($conditional);
                if ($response->status() === 304) {
                    if (! $this->valid($cached)) {
                        throw new EsiRequestException('incomplete', 30, $response);
                    }
                    $merged = [];
                    foreach (array_merge($cached['headers'], $response->headers()) as $name => $values) {
                        $merged[strtolower($name)] = $values;
                    }
                    if (! $response->header('Age')) {
                        unset($merged['age']);
                    }
                    // Some servers attach Content-Length:0 to a 304. It does
                    // not describe the stored representation's payload.
                    unset($merged['content-length']);
                    $response = new EsiResponse(new Response(new PsrResponse(200, $merged, $cached['body'])));
                }
                if ($response->status() === 200 && $response->data() !== null) {
                    $metadata = $this->responseMetadata($response);
                    $control = strtolower((string) $response->header('Cache-Control'));
                    $vary = strtolower((string) $response->header('Vary'));
                    $hasPolicy = $response->header('Cache-Control') || $response->header('Expires');
                    $supportedVary = array_diff(array_filter(array_map('trim', explode(',', $vary))), ['accept', 'accept-language', 'accept-encoding', 'authorization']) === [];
                    if ($hasPolicy && ! str_contains($control, 'no-store') && $supportedVary && strlen($response->response->body()) <= config('esi.http_cache_max_bytes', 4 * 1024 * 1024)) {
                        $entry = array_merge($metadata, ['status' => 200, 'body' => $response->response->body(), 'headers' => $response->headers(), 'fresh_until' => $metadata['source_expires_at'] ?? now()->timestamp]);
                        $store->put($key, $entry, max(1, (int) config('esi.http_cache_retention', 86400)));
                    } elseif (str_contains($control, 'no-store')) {
                        $store->forget($key);
                    }

                    return new EsiResponse($response->response, $metadata);
                }

                return $response;
            });
        } catch (EsiRequestException $e) {
            throw $e;
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            throw new EsiRequestException('coordination', 5);
        }
    }

    public function metadata(string $endpoint, ?Character $character = null, ?array $params = null, array $headers = []): ?array
    {
        $cached = Cache::store(config('esi.http_cache_store') ?: config('cache.default'))->get($this->key($endpoint, $character, $params, $headers));

        return $this->valid($cached) ? array_intersect_key($cached, array_flip(['source_updated_at', 'validated_at', 'source_expires_at'])) : null;
    }

    /** Dates are Unix seconds; an unknown expiry is never claimed fresh. */
    public function responseMetadata(EsiResponse $response): array
    {
        if ($response->sourceMetadata !== null) {
            return $response->sourceMetadata;
        }
        $observedAt = now()->timestamp;
        $date = strtotime((string) $response->header('Date')) ?: $observedAt;
        $modified = strtotime((string) $response->header('Last-Modified')) ?: $date;
        $age = max(0, (int) $response->header('Age'), $observedAt - $date);
        $control = strtolower((string) $response->header('Cache-Control'));
        $expiry = null;
        if ($control !== '') {
            if (preg_match('/(?:^|,)\s*s-maxage\s*=\s*"?(\d+)/', $control, $match) || preg_match('/(?:^|,)\s*max-age\s*=\s*"?(\d+)/', $control, $match)) {
                $expiry = $observedAt + max(0, (int) $match[1] - $age);
            }
            if (str_contains($control, 'no-cache') || str_contains($control, 'no-store')) {
                $expiry = $observedAt;
            }
        }
        if ($expiry === null && $response->header('Expires')) {
            $expiry = $observedAt + max(0, (int) strtotime($response->header('Expires')) - $date - $age);
        }

        return ['source_updated_at' => $modified, 'validated_at' => $observedAt, 'source_expires_at' => $expiry];
    }

    protected function key(string $endpoint, ?Character $character, ?array $params, array $headers): string
    {
        $parts = parse_url($endpoint);
        $path = preg_replace('#^/(latest|legacy|dev|v\d+)(?=/)#', '', $parts['path'] ?? $endpoint);
        parse_str($parts['query'] ?? '', $query);
        $query = array_replace_recursive($query, $params ?? []);
        $sort = function (array &$values) use (&$sort): void {
            ksort($values);
            foreach ($values as &$value) {
                if (is_array($value)) {
                    $sort($value);
                }
            }
        };
        $sort($query);
        $normalizedHeaders = array_change_key_case($headers, CASE_LOWER);
        $scopes = $character?->scopes ?? [];
        $roles = $character?->roles ?? [];
        sort($scopes);
        sort($roles);
        $subject = $character ? [$character->character_id, $character->user_id, $character->corporation_id, $character->is_esi_active, $scopes, $roles] : null;
        $canonical = [strtolower($parts['scheme'] ?? 'https'), strtolower($parts['host'] ?? 'esi.evetech.net'), $parts['port'] ?? 443, rtrim($path, '/'), http_build_query($query), config('esi.compatibility_date'), $normalizedHeaders['accept-language'] ?? 'en', $normalizedHeaders['accept'] ?? 'application/json', $normalizedHeaders['accept-encoding'] ?? '', $subject];

        return 'esi.http.v1.'.hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }

    protected function valid(mixed $cached): bool
    {
        return is_array($cached) && is_string($cached['body'] ?? null) && is_array($cached['headers'] ?? null) && isset($cached['fresh_until']);
    }

    protected function response(array $cached): EsiResponse
    {
        $headers = $cached['headers'];
        // Age includes residence time, allowing callers to calculate the same
        // freshness deadline after this representation is served from cache.
        $headers['Age'] = [(string) max(0, now()->timestamp - $cached['validated_at'] + (int) ($this->header($headers, 'Age') ?? 0))];

        return new EsiResponse(new Response(new PsrResponse($cached['status'], $headers, $cached['body'])), array_intersect_key($cached, array_flip(['source_updated_at', 'validated_at', 'source_expires_at'])));
    }

    protected function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $values) {
            if (strcasecmp($key, $name) === 0) {
                return (string) ($values[0] ?? '');
            }
        }

        return null;
    }
}
