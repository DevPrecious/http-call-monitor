<?php

namespace HttpCallMonitor\Tests;

use HttpCallMonitor\CallRepository;
use HttpCallMonitor\HostStats;
use Illuminate\Console\Scheduling\Schedule;
use Orchestra\Testbench\Attributes\DefineEnvironment;

class ReportingTest extends TestCase
{
    private function hostStats(string $host): HostStats
    {
        $matching = array_filter(
            $this->app->make(CallRepository::class)->perHost(24),
            fn (HostStats $stats) => $stats->host === $host,
        );

        return array_values($matching)[0];
    }

    public function test_it_counts_each_kind_of_outcome(): void
    {
        foreach ([200, 200, 301, 404, 429, 500, 503] as $status) {
            $this->seedCall('api.example.com', $status);
        }
        $this->seedCall('api.example.com', null);

        $stats = $this->hostStats('api.example.com');

        $this->assertSame(8, $stats->calls);
        $this->assertSame(2, $stats->clientErrors);
        $this->assertSame(2, $stats->serverErrors);
        $this->assertSame(1, $stats->noResponse);
    }

    public function test_failure_rate_counts_5xx_and_no_response_but_not_4xx(): void
    {
        foreach ([200, 200, 200, 200, 200, 200, 404, 404, 500] as $status) {
            $this->seedCall('api.example.com', $status);
        }
        $this->seedCall('api.example.com', null);

        $this->assertEqualsWithDelta(20.0, $this->hostStats('api.example.com')->failureRate(), 0.001);
    }

    public function test_percentiles_use_the_nearest_rank(): void
    {
        $durations = range(1, 100);
        shuffle($durations);

        foreach ($durations as $duration) {
            $this->seedCall('api.example.com', 200, $duration);
        }

        $stats = $this->hostStats('api.example.com');

        $this->assertSame(50, $stats->p50);
        $this->assertSame(95, $stats->p95);
    }

    public function test_a_single_call_is_its_own_percentile(): void
    {
        $this->seedCall('api.example.com', 200, 42);

        $stats = $this->hostStats('api.example.com');

        $this->assertSame(42, $stats->p50);
        $this->assertSame(42, $stats->p95);
    }

    public function test_calls_with_no_response_are_left_out_of_latency(): void
    {
        $this->seedCall('api.example.com', 200, 100);
        $this->seedCall('api.example.com', null, 30000);

        $this->assertSame(100, $this->hostStats('api.example.com')->p95);
    }

    public function test_latency_is_empty_when_nothing_ever_responded(): void
    {
        $this->seedCall('api.example.com', null, 30000);

        $this->assertNull($this->hostStats('api.example.com')->p50);
    }

    public function test_calls_outside_the_window_are_excluded(): void
    {
        $this->seedCall('api.example.com', 200, 10);
        $this->seedCall('api.example.com', 500, 9000, hoursAgo: 25);

        $stats = $this->hostStats('api.example.com');

        $this->assertSame(1, $stats->calls);
        $this->assertSame(10, $stats->p95);
    }

    public function test_hosts_are_kept_separate_and_sorted_worst_first(): void
    {
        $this->seedCall('healthy.example.com', 200, 10);
        $this->seedCall('healthy.example.com', 200, 10);
        $this->seedCall('failing.example.com', 500, 900);

        $hosts = $this->app->make(CallRepository::class)->perHost(24);

        $this->assertSame(['failing.example.com', 'healthy.example.com'], array_column($hosts, 'host'));
        $this->assertSame(900, $hosts[0]->p95);
        $this->assertSame(10, $hosts[1]->p95);
    }

    public function test_recent_failures_are_newest_first_and_only_failures(): void
    {
        $this->seedCall('api.example.com', 500, path: '/older');
        $this->seedCall('api.example.com', 200, path: '/fine');
        $this->seedCall('api.example.com', 404, path: '/not-found');
        $this->seedCall('api.example.com', null, path: '/newer', error: 'cURL error 6');

        $failures = $this->app->make(CallRepository::class)->recentFailures(24);

        $this->assertSame(['/newer', '/older'], array_column($failures, 'path'));
    }

    public function test_stats_command_prints_a_row_per_host(): void
    {
        $this->seedCall('api.example.com', 200, 120);
        $this->seedCall('api.example.com', 500, 120);

        $this->artisan('http-call-monitor:stats')
            ->expectsTable(
                ['Host', 'Calls', 'Failure rate', '4xx', '5xx', 'No response', 'p50', 'p95'],
                [['api.example.com', '2', '50.0%', '0', '1', '0', '120 ms', '120 ms']],
            )
            ->assertSuccessful();
    }

    public function test_stats_command_explains_when_there_is_nothing_to_show(): void
    {
        $this->artisan('http-call-monitor:stats')
            ->expectsOutput('No calls recorded in the last 24 hour(s).')
            ->assertSuccessful();
    }

    public function test_stats_command_rejects_a_bad_window(): void
    {
        $this->artisan('http-call-monitor:stats', ['--hours' => 'abc'])->assertFailed();
    }

    public function test_prune_deletes_only_calls_past_retention(): void
    {
        $this->seedCall('old.example.com', hoursAgo: 24 * 15);
        $this->seedCall('recent.example.com', hoursAgo: 24 * 13);

        $this->artisan('http-call-monitor:prune')->assertSuccessful();

        $this->assertSame('recent.example.com', $this->onlyCall()->host);
    }

    public function test_prune_clears_more_than_one_batch(): void
    {
        for ($i = 0; $i < 1205; $i++) {
            $this->seedCall('old.example.com', hoursAgo: 24 * 30);
        }

        $this->assertSame(1205, $this->app->make(CallRepository::class)->prune(14));
        $this->assertSame(0, $this->storedCalls());
    }

    public function test_prune_rejects_a_bad_retention(): void
    {
        $this->seedCall('old.example.com', hoursAgo: 24 * 30);

        $this->artisan('http-call-monitor:prune', ['--days' => '0'])->assertFailed();

        $this->assertSame(1, $this->storedCalls());
    }

    public function test_prune_is_scheduled_daily(): void
    {
        $this->assertSame('0 0 * * *', $this->scheduledPrune()?->expression);
    }

    #[DefineEnvironment('keepForever')]
    public function test_disabling_retention_keeps_everything_and_unschedules_prune(): void
    {
        $this->seedCall('old.example.com', hoursAgo: 24 * 365);

        $this->artisan('http-call-monitor:prune')->assertSuccessful();

        $this->assertSame(1, $this->storedCalls());
        $this->assertNull($this->scheduledPrune());
    }

    protected function keepForever($app): void
    {
        $app['config']->set('http-call-monitor.retention_days', null);
    }

    private function scheduledPrune(): ?object
    {
        return collect($this->app->make(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'http-call-monitor:prune'));
    }
}
