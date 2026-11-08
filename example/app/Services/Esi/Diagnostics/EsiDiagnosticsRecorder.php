<?php

namespace App\Services\Esi\Diagnostics;

use App\Models\Character;
use Illuminate\Http\Client\Response;

/**
 * Passive capture facade. Nothing here may alter an ESI outcome: every public
 * method is a no-op when disabled and swallows its own failures.
 */
class EsiDiagnosticsRecorder
{
    /** Events dropped in this process (oversized or sink failure). */
    public int $dropped = 0;

    private bool $recording = false;

    public function __construct(private EsiDiagnosticsSink $sink, private EsiDiagnosticsContext $context) {}

    public function enabled(): bool
    {
        return (bool) config('esi_diagnostics.enabled');
    }

    public function context(): EsiDiagnosticsContext
    {
        return $this->context;
    }

    /** One wire attempt that produced an HTTP response. Call before any cache or budget processing can throw. */
    public function httpAttempt(string $method, string $endpoint, ?Character $character, Response $response, ?int $durationMs, string $group): void
    {
        if (! $this->enabled()) {
            return;
        }
        $this->guard(function () use ($method, $endpoint, $character, $response, $durationMs, $group) {
            $status = $response->status();
            $reset = $response->header('X-Esi-Error-Limit-Reset');
            $remain = $response->header('X-Esi-Error-Limit-Remain');
            $message = $status >= 400 && $status < 500 ? $this->errorMessage($response) : null;
            $retry = $response->header('Retry-After');
            $tokenRemaining = $response->header('X-Ratelimit-Remaining');
            $tokenUsed = $response->header('X-Ratelimit-Used');
            $this->emit($method, $endpoint, $character, [
                'kind' => 'http_attempt',
                'http_status' => $status,
                'reason' => EsiErrorClassifier::classify($status, $message),
                'duration_ms' => $durationMs,
                'response_received' => true,
                'legacy_remaining' => $this->number($remain),
                'legacy_reset_seconds' => $this->number($reset),
                'legacy_reset_at' => $this->number($reset) === null ? null : time() + $this->number($reset),
                'limiter_group' => $response->header('X-Ratelimit-Group') ?: ($group === 'legacy' ? null : $group),
                'token_limit' => $response->header('X-Ratelimit-Limit'),
                'token_used' => $this->number($tokenUsed),
                'token_remaining' => $this->number($tokenRemaining),
                'retry_after_seconds' => $this->number($retry),
                'upstream_request_id' => $response->header('X-Esi-Request-Id') ?: $response->header('X-Request-Id'),
            ]);
        });
    }

    public function connectionFailure(string $method, string $endpoint, ?Character $character, ?int $durationMs): void
    {
        $this->simple('connection_failure', $method, $endpoint, $character, 'connection', ['duration_ms' => $durationMs]);
    }

    /** A request that was withheld locally; no ESI request was sent. */
    public function localDeferral(string $method, string $endpoint, ?Character $character, string $reason): void
    {
        if (config('esi_diagnostics.capture_deferrals')) {
            $this->simple('local_deferral', $method, $endpoint, $character, $reason);
        }
    }

    public function cache(string $kind, string $endpoint, ?Character $character): void
    {
        if (config('esi_diagnostics.capture_cache')) {
            $this->simple($kind, 'get', $endpoint, $character, 'cache');
        }
    }

    public function circuit(string $kind, string $reason, array $extra = []): void
    {
        $this->simple($kind, null, '', null, $reason, $extra);
    }

    private function simple(string $kind, ?string $method, string $endpoint, ?Character $character, string $reason, array $extra = []): void
    {
        if (! $this->enabled()) {
            return;
        }
        $this->guard(fn () => $this->emit($method, $endpoint, $character, array_merge(['kind' => $kind, 'reason' => $reason], $extra)));
    }

    private function emit(?string $method, string $endpoint, ?Character $character, array $fields): void
    {
        $context = $this->context->get();
        $event = EsiDiagnosticEvent::make(array_merge($context, $fields, [
            'method' => $method === null ? null : strtoupper($method),
            'operation_key' => $endpoint === '' ? '' : EsiRouteNormalizer::normalize($method ?? 'get', $endpoint),
            'compatibility_date' => config('esi.compatibility_date'),
            'source_identity' => config('esi.source_identity'),
            'application_id' => config('esi.application_id'),
            'character_id' => $character?->character_id ?? ($context['character_id'] ?? null),
            'release' => config('esi_diagnostics.release'),
        ]));
        if (strlen(json_encode($event)) > (int) config('esi_diagnostics.max_event_bytes', 4096)) {
            $this->dropped++;

            return;
        }
        $this->sink->push($event);
    }

    /** Telemetry failures never propagate, and recording never records itself. */
    private function guard(callable $callback): void
    {
        if ($this->recording) {
            return;
        }
        $this->recording = true;
        try {
            $callback();
        } catch (\Throwable $e) {
            $this->dropped++;
        } finally {
            $this->recording = false;
        }
    }

    private function errorMessage(Response $response): ?string
    {
        $data = $response->json();

        return is_array($data) && is_string($data['error'] ?? null) ? $data['error'] : null;
    }

    private function number(mixed $value): ?int
    {
        return is_string($value) && ctype_digit($value) ? (int) $value : (is_int($value) ? $value : null);
    }
}
