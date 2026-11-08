<?php

namespace App\Services\Esi;

use Illuminate\Http\Client\Response;

final class EsiResponse
{
    public function __construct(public readonly Response $response, public readonly ?array $sourceMetadata = null) {}

    public function data(): ?array
    {
        $data = $this->response->json();

        return is_array($data) ? $data : null;
    }

    public function status(): int
    {
        return $this->response->status();
    }

    public function successful(): bool
    {
        return $this->response->successful();
    }

    public function header(string $name): ?string
    {
        return $this->response->header($name);
    }

    public function headers(): array
    {
        return $this->response->headers();
    }

    public function retryAfterSeconds(): int
    {
        $value = $this->header('Retry-After');
        if ($value === null || $value === '') {
            return 0;
        }

        return ctype_digit($value) ? (int) $value : max(0, (int) strtotime($value) - now()->timestamp);
    }
}
