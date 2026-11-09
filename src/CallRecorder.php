<?php

namespace HttpCallMonitor;

use Closure;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Guzzle middleware that times each outbound request.
 *
 * Middleware is used instead of the HTTP client events because the ConnectionFailed
 * event carries a new request object, so a failure can't be matched to its start time.
 */
final class CallRecorder
{
    /**
     * @param  list<string>  $ignoredHosts
     */
    public function __construct(
        private readonly CallBuffer $buffer,
        private readonly Redactor $redactor,
        private readonly array $ignoredHosts = [],
    ) {}

    public function __invoke(callable $handler): Closure
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            $startedAt = hrtime(true);

            return $handler($request, $options)->then(
                function (ResponseInterface $response) use ($request, $startedAt) {
                    $this->record($request, $startedAt, $response->getStatusCode(), null);

                    return $response;
                },
                function (mixed $reason) use ($request, $startedAt) {
                    $this->record($request, $startedAt, null, $reason);

                    return Create::rejectionFor($reason);
                },
            );
        };
    }

    /**
     * Monitoring must never affect the call being monitored, so nothing escapes here.
     */
    private function record(RequestInterface $request, int $startedAt, ?int $status, mixed $reason): void
    {
        try {
            $host = $this->redactor->host($request->getUri());

            if (Str::is($this->ignoredHosts, $host)) {
                return;
            }

            $this->buffer->push([
                'host' => $host,
                'method' => mb_substr(strtoupper($request->getMethod()), 0, 10),
                'path' => $this->redactor->path($request->getUri()),
                'status' => $status,
                'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
                'error' => $status === null ? $this->redactor->error($reason) : null,
                'created_at' => Date::now(),
            ]);
        } catch (Throwable $exception) {
            $this->buffer->warnOnce($exception);
        }
    }
}
