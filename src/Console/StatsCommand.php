<?php

namespace HttpCallMonitor\Console;

use HttpCallMonitor\CallRepository;
use HttpCallMonitor\HostStats;
use Illuminate\Console\Command;

final class StatsCommand extends Command
{
    protected $signature = 'http-call-monitor:stats {--hours=24 : How many hours back to include}';

    protected $description = 'Show latency and failure rate for each third-party API';

    public function handle(CallRepository $calls): int
    {
        $hours = (int) $this->option('hours');

        if ($hours < 1) {
            $this->error('--hours must be a whole number of at least 1.');

            return self::INVALID;
        }

        $hosts = $calls->perHost($hours);

        if ($hosts === []) {
            $this->info("No calls recorded in the last {$hours} hour(s).");

            return self::SUCCESS;
        }

        $this->table(
            ['Host', 'Calls', 'Failure rate', '4xx', '5xx', 'No response', 'p50', 'p95'],
            array_map(fn (HostStats $host) => [
                $host->host,
                number_format($host->calls),
                number_format($host->failureRate(), 1).'%',
                number_format($host->clientErrors),
                number_format($host->serverErrors),
                number_format($host->noResponse),
                HostStats::formatLatency($host->p50),
                HostStats::formatLatency($host->p95),
            ], $hosts),
        );

        return self::SUCCESS;
    }
}
