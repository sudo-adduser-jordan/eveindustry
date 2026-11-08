<?php

namespace App\Providers;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Server;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * OpenAPI docs for /api/v1 (Scramble).
 *
 * The UI at /docs/api is open in the local environment only (Scramble's
 * RestrictedDocsAccess); elsewhere it needs the viewApiDocs gate, which is
 * limited to admins.
 */
class ApiDocsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::define('viewApiDocs', fn (?User $user = null) => $user?->isAdmin() ?? false);

        Scramble::afterOpenApiGenerated(function (OpenApi $openApi) {
            // Relative server URL so the committed spec does not depend on APP_URL.
            $openApi->servers = [Server::make('/api/v1')->setDescription('Same origin')];

            // bootstrap/app.php adds a machine-readable `code` to every /api/v1 error.
            foreach ($openApi->components->responses as $response) {
                $schema = $response->content['application/json'] ?? null;
                if (! $schema instanceof Schema || ! $schema->type instanceof ObjectType) {
                    continue;
                }

                $schema->type->addProperty('code', (new StringType)
                    ->enum([...array_values(ApiResponse::ERROR_CODES), 'http_error'])
                    ->setDescription('Machine-readable error code.'));
                $schema->type->addRequired(['code']);
            }
        });
    }
}
