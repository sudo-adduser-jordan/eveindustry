<?php

use App\Http\Responses\ApiResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Only the versioned API gets forced-JSON errors. The legacy Blade JSON
// endpoints under /api/... in web.php keep Laravel's default behaviour.
$isVersionedApi = fn (Request $request): bool => $request->is('api/v1', 'api/v1/*');

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();

        // Behind the bundled Caddy (or any reverse proxy) the request arrives over
        // plain HTTP; trust the proxy's forwarded headers so URLs and cookies are
        // https. Defaults to private networks only. Use '*' only if nothing but
        // your proxy can reach the web container.
        $trusted = env('TRUSTED_PROXIES', '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.1');
        $middleware->trustProxies(at: $trusted === '*' ? '*' : array_map('trim', explode(',', $trusted)));

        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'super-admin' => \App\Http\Middleware\SuperAdminMiddleware::class,
            'stock-manager' => \App\Http\Middleware\StockManagerEnabled::class,
            'demo-guard' => \App\Http\Middleware\BlockDemoUser::class,
            'feature' => \App\Http\Middleware\EnsureFeatureEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) use ($isVersionedApi) {
        // 401/403/404/422 etc. render as JSON for /api/v1, never a redirect.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $isVersionedApi($request) || $request->expectsJson()
        );

        // Add a machine-readable `code` to every /api/v1 error body.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) use ($isVersionedApi) {
            if (! $isVersionedApi($request) || ! $response instanceof JsonResponse) {
                return $response;
            }

            $body = $response->getData(true);
            if (is_array($body) && ! isset($body['code'])) {
                $body['code'] = ApiResponse::codeForStatus($response->getStatusCode());
                $response->setData($body);
            }

            return $response;
        });
    })->create();
