<?php

namespace HttpCallMonitor\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class RecordingTest extends TestCase
{
    public function test_it_records_a_successful_call(): void
    {
        Http::fake(['*' => Http::response(['id' => 1], 201)]);

        $response = Http::post('https://API.example.com/v1/charges');
        $this->flushCalls();

        $this->assertSame(201, $response->status());
        $this->assertSame(['id' => 1], $response->json());

        $call = $this->onlyCall();
        $this->assertSame('api.example.com', $call->host);
        $this->assertSame('POST', $call->method);
        $this->assertSame('/v1/charges', $call->path);
        $this->assertEquals(201, $call->status);
        $this->assertGreaterThanOrEqual(0, $call->duration_ms);
        $this->assertNull($call->error);
    }

    public function test_it_measures_how_long_the_call_took(): void
    {
        Http::fake(function () {
            usleep(30_000);

            return Http::response();
        });

        Http::get('https://api.example.com/slow');
        $this->flushCalls();

        $this->assertGreaterThanOrEqual(25, $this->onlyCall()->duration_ms);
    }

    public function test_query_strings_and_credentials_are_never_stored(): void
    {
        Http::fake(['*' => Http::response()]);

        Http::get('https://user:hunter2@api.example.com/v1/x?api_key=SECRET');
        $this->flushCalls();

        $stored = json_encode($this->onlyCall());
        $this->assertStringNotContainsString('SECRET', $stored);
        $this->assertStringNotContainsString('hunter2', $stored);
    }

    public function test_a_server_error_is_recorded_and_returned_to_the_app_untouched(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);

        $response = Http::get('https://api.example.com/v1/x');
        $this->flushCalls();

        $this->assertSame(503, $response->status());
        $this->assertSame('down', $response->body());
        $this->assertEquals(503, $this->onlyCall()->status);
    }

    public function test_a_connection_failure_is_recorded_and_still_thrown(): void
    {
        $url = 'https://api.example.com/v1/x?api_key=SECRET';

        // MockHandler rejects the promise like real cURL does. Http::fake() throws instead.
        $transport = new MockHandler([new ConnectException(
            "cURL error 28: Operation timed out (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for {$url}",
            new Psr7Request('GET', $url),
        )]);

        try {
            Http::setHandler($transport)->get($url);
            $this->fail('The connection failure should have reached the app.');
        } catch (ConnectionException $exception) {
            $this->assertStringContainsString('cURL error 28', $exception->getMessage());
        }

        $this->flushCalls();

        $call = $this->onlyCall();
        $this->assertNull($call->status);
        $this->assertSame('cURL error 28: Operation timed out for [url]', $call->error);
    }

    public function test_each_retry_attempt_is_a_separate_call(): void
    {
        Http::fake(['*' => Http::sequence()->push('', 503)->push('', 200)]);

        Http::retry(2, 0, throw: false)->get('https://api.example.com/v1/x');
        $this->flushCalls();

        $this->assertEquals([503, 200], DB::table('http_calls')->orderBy('id')->pluck('status')->all());
    }

    public function test_concurrent_pool_requests_are_recorded(): void
    {
        Http::fake(['*' => Http::response()]);

        Http::pool(fn (Pool $pool) => [
            $pool->get('https://a.example.com/x'),
            $pool->get('https://b.example.com/y'),
        ]);
        $this->flushCalls();

        $this->assertEqualsCanonicalizing(['a.example.com', 'b.example.com'], DB::table('http_calls')->pluck('host')->all());
    }

    #[DefineEnvironment('ignoreInternalHosts')]
    public function test_ignored_hosts_are_skipped(): void
    {
        Http::fake(['*' => Http::response()]);

        Http::get('https://metrics.internal/ping');
        Http::get('https://api.example.com/ping');
        $this->flushCalls();

        $this->assertSame('api.example.com', $this->onlyCall()->host);
    }

    protected function ignoreInternalHosts($app): void
    {
        $app['config']->set('http-call-monitor.ignore_hosts', ['*.INTERNAL']);
    }

    #[DefineEnvironment('disableRecording')]
    public function test_nothing_is_recorded_when_disabled(): void
    {
        Http::fake(['*' => Http::response()]);

        Http::get('https://api.example.com/ping');
        $this->flushCalls();
        $this->app->terminate();

        $this->assertSame(0, $this->storedCalls());
    }

    protected function disableRecording($app): void
    {
        $app['config']->set('http-call-monitor.enabled', false);
    }

    public function test_recording_defaults_to_off_in_a_host_apps_test_suite(): void
    {
        $name = 'HTTP_CALL_MONITOR_ENABLED';
        $original = getenv($name);
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);

        try {
            $this->assertFalse((require __DIR__.'/../config/http-call-monitor.php')['enabled']);
        } finally {
            putenv("{$name}={$original}");
            $_ENV[$name] = $_SERVER[$name] = $original;
        }
    }

    public function test_a_storage_problem_never_reaches_the_app_and_is_logged_once(): void
    {
        config(['logging.default' => 'null']);
        $warnings = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$warnings) {
            $warnings[] = $event->message;
        });

        Schema::drop('http_calls');
        Http::fake(['*' => Http::response('ok')]);

        $this->assertSame('ok', Http::get('https://api.example.com/1')->body());
        $this->flushCalls();
        Http::get('https://api.example.com/2');
        $this->flushCalls();

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('could not record calls', $warnings[0]);
    }

    public function test_a_failed_call_survives_the_rollback_it_causes(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        try {
            DB::transaction(function () {
                Http::get('https://api.example.com/v1/x')->throw();
            });
            $this->fail('The request exception should have been thrown.');
        } catch (RequestException) {
            // Expected: this is what rolls the transaction back.
        }

        $this->flushCalls();

        $this->assertSame(1, $this->storedCalls());
    }

    #[DefineEnvironment('flushAfterTwoCalls')]
    public function test_a_full_buffer_is_written_straight_away(): void
    {
        Http::fake(['*' => Http::response()]);

        Http::get('https://api.example.com/1');
        $this->assertSame(0, $this->storedCalls());

        Http::get('https://api.example.com/2');
        $this->assertSame(2, $this->storedCalls());
    }

    #[DefineEnvironment('flushAfterTwoCalls')]
    public function test_a_full_buffer_waits_for_an_open_transaction(): void
    {
        Http::fake(['*' => Http::response()]);

        DB::transaction(function () {
            Http::get('https://api.example.com/1');
            Http::get('https://api.example.com/2');
        });

        $this->assertSame(0, $this->storedCalls());

        $this->flushCalls();
        $this->assertSame(2, $this->storedCalls());
    }

    protected function flushAfterTwoCalls($app): void
    {
        $app['config']->set('http-call-monitor.flush_at', 2);
    }

    public function test_calls_are_written_when_the_app_terminates(): void
    {
        Http::fake(['*' => Http::response()]);

        Http::get('https://api.example.com/1');
        $this->assertSame(0, $this->storedCalls());

        $this->app->terminate();
        $this->assertSame(1, $this->storedCalls());
    }

    #[DataProvider('queueEvents')]
    public function test_calls_are_written_after_each_queued_job(callable $makeEvent): void
    {
        Http::fake(['*' => Http::response()]);

        Http::get('https://api.example.com/1');
        event($makeEvent(Mockery::mock(Job::class)));

        $this->assertSame(1, $this->storedCalls());
    }

    public static function queueEvents(): array
    {
        return [
            'job finished' => [fn (Job $job) => new JobProcessed('redis', $job)],
            'job threw and may retry' => [fn (Job $job) => new JobExceptionOccurred('redis', $job, new RuntimeException)],
        ];
    }
}
