<?php

namespace App\Services\Esi\Diagnostics;

/** Turns a concrete endpoint into "method /templated/path" without query strings or IDs. */
final class EsiRouteNormalizer
{
    public static function normalize(string $method, string $endpoint): string
    {
        $method = strtolower($method);
        $path = parse_url($endpoint, PHP_URL_PATH) ?: $endpoint;
        $path = rtrim(preg_replace('#^/(latest|legacy|dev|v\d+)(?=/)#', '', $path), '/');
        foreach (array_keys(config('esi_routes', [])) as $route) {
            [$routeMethod, $template] = explode(' ', $route, 2);
            $segments = array_map(fn ($segment) => str_starts_with($segment, '{') ? '[^/]+' : preg_quote($segment, '#'), explode('/', $template));
            if ($routeMethod === $method && preg_match('#^'.implode('/', $segments).'$#', $path)) {
                return $route;
            }
        }

        // Unknown route: collapse numeric segments so IDs never explode the key space.
        return $method.' '.preg_replace('#/\d+(?=/|$)#', '/{id}', $path);
    }
}
