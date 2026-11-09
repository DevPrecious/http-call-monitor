<?php

namespace HttpCallMonitor;

use Carbon\CarbonInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Date;
use stdClass;

final class CallRepository
{
    private const INSERT_BATCH = 200;

    private const DELETE_BATCH = 1000;

    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly ?string $connection,
        private readonly string $table,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function insert(array $rows): void
    {
        foreach (array_chunk($rows, self::INSERT_BATCH) as $batch) {
            $this->calls()->insert($batch);
        }
    }

    public function inTransaction(): bool
    {
        return $this->connection()->transactionLevel() > 0;
    }

    /**
     * @return list<HostStats> Worst failure rate first.
     */
    public function perHost(int $hours): array
    {
        $since = Date::now()->subHours($hours);

        $rows = $this->calls()
            ->where('created_at', '>=', $since)
            ->groupBy('host')
            ->select('host')
            ->selectRaw('count(*) as calls')
            ->selectRaw('sum(case when status between 400 and 499 then 1 else 0 end) as client_errors')
            ->selectRaw('sum(case when status >= 500 then 1 else 0 end) as server_errors')
            ->selectRaw('sum(case when status is null then 1 else 0 end) as no_response')
            ->get();

        $stats = $rows->map(function (stdClass $row) use ($since) {
            $responses = (int) $row->calls - (int) $row->no_response;

            return new HostStats(
                host: $row->host,
                calls: (int) $row->calls,
                clientErrors: (int) $row->client_errors,
                serverErrors: (int) $row->server_errors,
                noResponse: (int) $row->no_response,
                p50: $this->latencyPercentile($row->host, $since, $responses, 0.50),
                p95: $this->latencyPercentile($row->host, $since, $responses, 0.95),
            );
        })->all();

        usort($stats, fn (HostStats $a, HostStats $b) => [$b->failureRate(), $b->calls] <=> [$a->failureRate(), $a->calls]);

        return $stats;
    }

    /**
     * @return list<stdClass> Newest first.
     */
    public function recentFailures(int $hours, int $limit = 20): array
    {
        return $this->calls()
            ->where('created_at', '>=', Date::now()->subHours($hours))
            ->where(fn (Builder $query) => $query->whereNull('status')->orWhere('status', '>=', 500))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * Deletes in small batches so a large cleanup doesn't hold a long lock.
     */
    public function prune(int $days): int
    {
        $cutoff = Date::now()->subDays($days);
        $total = 0;

        do {
            $deleted = $this->calls()->where('created_at', '<', $cutoff)->limit(self::DELETE_BATCH)->delete();
            $total += $deleted;
        } while ($deleted > 0);

        return $total;
    }

    /**
     * Nearest-rank percentile, using OFFSET because percentile SQL differs per database.
     * Calls with no response are left out: a DNS failure says nothing about API latency.
     */
    private function latencyPercentile(string $host, CarbonInterface $since, int $responses, float $percentile): ?int
    {
        if ($responses === 0) {
            return null;
        }

        $duration = $this->calls()
            ->where('host', $host)
            ->where('created_at', '>=', $since)
            ->whereNotNull('status')
            ->orderBy('duration_ms')
            ->offset((int) ceil($responses * $percentile) - 1)
            ->value('duration_ms');

        return $duration === null ? null : (int) $duration;
    }

    private function calls(): Builder
    {
        return $this->connection()->table($this->table);
    }

    private function connection(): ConnectionInterface
    {
        return $this->connections->connection($this->connection);
    }
}
