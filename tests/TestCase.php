<?php

namespace HttpCallMonitor\Tests;

use HttpCallMonitor\CallBuffer;
use HttpCallMonitor\HttpCallMonitorServiceProvider;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase as BaseTestCase;
use stdClass;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();
    }

    protected function getPackageProviders($app): array
    {
        return [HttpCallMonitorServiceProvider::class];
    }

    protected function flushCalls(): void
    {
        $this->app->make(CallBuffer::class)->flush();
    }

    protected function storedCalls(): int
    {
        return DB::table('http_calls')->count();
    }

    protected function onlyCall(): stdClass
    {
        return DB::table('http_calls')->sole();
    }

    protected function seedCall(string $host, ?int $status = 200, int $duration = 100, int $hoursAgo = 0, string $path = '/v1/x', ?string $error = null): void
    {
        DB::table('http_calls')->insert([
            'host' => $host,
            'method' => 'GET',
            'path' => $path,
            'status' => $status,
            'duration_ms' => $duration,
            'error' => $error,
            'created_at' => Date::now()->subHours($hoursAgo),
        ]);
    }
}
