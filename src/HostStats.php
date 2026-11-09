<?php

namespace HttpCallMonitor;

final readonly class HostStats
{
    public function __construct(
        public string $host,
        public int $calls,
        public int $clientErrors,
        public int $serverErrors,
        public int $noResponse,
        public ?int $p50,
        public ?int $p95,
    ) {}

    /**
     * Percentage of calls where the API itself failed. 4xx responses are excluded
     * because they usually mean the request was wrong, not that the API is down.
     */
    public function failureRate(): float
    {
        return $this->calls === 0 ? 0.0 : ($this->serverErrors + $this->noResponse) / $this->calls * 100;
    }

    public static function formatLatency(?int $milliseconds): string
    {
        return $milliseconds === null ? '-' : number_format($milliseconds).' ms';
    }
}
