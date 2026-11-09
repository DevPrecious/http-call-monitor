<?php

namespace HttpCallMonitor;

use HttpCallMonitor\Console\PruneCommand;
use HttpCallMonitor\Console\StatsCommand;
use HttpCallMonitor\Http\Authorize;
use HttpCallMonitor\Http\DashboardController;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class HttpCallMonitorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/http-call-monitor.php', 'http-call-monitor');

        $this->app->singleton(CallRepository::class, fn (Application $app) => new CallRepository(
            connections: $app->make('db'),
            connection: $app->make('config')->get('http-call-monitor.storage.connection'),
            table: $app->make('config')->get('http-call-monitor.storage.table'),
        ));

        $this->app->singleton(CallBuffer::class, fn (Application $app) => new CallBuffer(
            calls: $app->make(CallRepository::class),
            log: $app->make('log'),
            flushAt: (int) $app->make('config')->get('http-call-monitor.flush_at'),
        ));

        $this->app->singleton(CallRecorder::class, fn (Application $app) => new CallRecorder(
            buffer: $app->make(CallBuffer::class),
            redactor: new Redactor($app->make('config')->get('http-call-monitor.path_replacements')),
            ignoredHosts: array_map('strtolower', $app->make('config')->get('http-call-monitor.ignore_hosts')),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'http-call-monitor');

        $this->publishes([
            __DIR__.'/../config/http-call-monitor.php' => config_path('http-call-monitor.php'),
        ], 'http-call-monitor-config');

        $this->bootDashboard();
        $this->bootConsole();

        if (config('http-call-monitor.enabled')) {
            $this->startRecording();
        }
    }

    /**
     * Everything is resolved here rather than lazily so that Octane's per-request
     * sandboxes share this one buffer instead of each building their own.
     */
    private function startRecording(): void
    {
        $this->app->make(Factory::class)->globalMiddleware($this->app->make(CallRecorder::class));

        $buffer = $this->app->make(CallBuffer::class);

        $this->app->terminating(fn () => $buffer->flush());

        // A queue worker never terminates between jobs.
        $this->app->make('events')->listen(
            [JobProcessed::class, JobExceptionOccurred::class, JobFailed::class],
            fn () => $buffer->flush(),
        );
    }

    private function bootDashboard(): void
    {
        if (! config('http-call-monitor.dashboard.enabled')) {
            return;
        }

        if (! Gate::has('viewHttpCallMonitor')) {
            Gate::define('viewHttpCallMonitor', fn ($user = null): bool => $this->app->environment('local'));
        }

        if ($this->app->routesAreCached()) {
            return;
        }

        Route::middleware([...config('http-call-monitor.dashboard.middleware'), Authorize::class])
            ->get(config('http-call-monitor.dashboard.path'), DashboardController::class)
            ->name('http-call-monitor.dashboard');
    }

    private function bootConsole(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([StatsCommand::class, PruneCommand::class]);

        if (config('http-call-monitor.retention_days') !== null) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
                $schedule->command(PruneCommand::class)->daily();
            });
        }
    }
}
