<?php

namespace App\Providers;

use App\Services\Esi\Diagnostics\EsiDiagnosticsContext;
use App\Services\Esi\Diagnostics\EsiDiagnosticsRecorder;
use App\Services\Esi\Diagnostics\EsiDiagnosticsSink;
use App\Services\Esi\Diagnostics\NullDiagnosticsSink;
use App\Services\Esi\Diagnostics\Streams\ArrayStream;
use App\Services\Esi\Diagnostics\Streams\EsiDiagnosticsStream;
use App\Services\Esi\Diagnostics\Streams\RedisStream;
use App\Services\Esi\Diagnostics\StreamSink;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class EsiDiagnosticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EsiDiagnosticsContext::class);
        $this->app->singleton(EsiDiagnosticsStream::class, function () {
            $config = config('esi_diagnostics.redis');

            return config('esi_diagnostics.sink') === 'redis'
                ? new RedisStream($config['connection'], $config['key'], $config['max_entries'])
                : new ArrayStream($config['max_entries']);
        });
        $this->app->bind(EsiDiagnosticsSink::class, fn ($app) => config('esi_diagnostics.sink') === 'redis'
            ? new StreamSink($app->make(EsiDiagnosticsStream::class))
            : new NullDiagnosticsSink);
        $this->app->singleton(EsiDiagnosticsRecorder::class);
    }

    public function boot(): void
    {
        // A long-lived worker must never carry one job's subject into the next.
        $clear = fn () => $this->app->make(EsiDiagnosticsContext::class)->clear();
        // Every queued job gets its class as job_class, a dataset derived from it, and its queue id as the
        // correlation id. Call sites with a more specific dataset override it through with().
        Queue::before(function (JobProcessing $event) {
            $class = $event->job->resolveName();
            $this->app->make(EsiDiagnosticsContext::class)->replace([
                'job_class' => $class,
                'dataset' => Str::snake(class_basename($class)),
                'correlation_id' => (string) $event->job->getJobId(),
            ]);
        });
        Queue::after($clear);
        Queue::failing($clear);

        // Scheduled and manual commands that call ESI inline (not the long-running queue workers).
        Event::listen(CommandStarting::class, function (CommandStarting $event) {
            $name = (string) $event->command;
            if ($name === '' || str_starts_with($name, 'queue:') || str_starts_with($name, 'horizon') || str_starts_with($name, 'schedule:')) {
                return;
            }
            $this->app->make(EsiDiagnosticsContext::class)->replace(['dataset' => str_replace(':', '_', $name), 'correlation_id' => (string) Str::ulid()]);
        });
        Event::listen(CommandFinished::class, $clear);
    }
}
