<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/** Reads legacy tokens during rollout; every new write is encrypted. */
class EsiToken implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        // A recognized encrypted payload must fail closed on an invalid key.
        return self::encrypted((string) $value) ? Crypt::decryptString($value) : (string) $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return config('esi.encrypt_tokens', false) ? Crypt::encryptString((string) $value) : (string) $value;
    }

    public static function encrypted(?string $value): bool
    {
        $payload = json_decode(base64_decode($value ?? '', true) ?: '', true);

        return is_array($payload) && isset($payload['iv'], $payload['value'], $payload['mac']);
    }
}
