<?php

namespace HttpCallMonitor;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Holds recorded calls in memory and writes them in batches.
 *
 * Calls aren't written as they happen because a failed API call often rolls back the
 * transaction around it, which would erase the record of that very failure.
 */
final class CallBuffer
{
    /** Past this size calls are written even inside a transaction, to keep memory bounded. */
    private const HARD_LIMIT = 1000;

    /** @var list<array<string, mixed>> */
    private array $rows = [];

    private bool $warned = false;

    public function __construct(
        private readonly CallRepository $calls,
        private readonly LoggerInterface $log,
        private readonly int $flushAt,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public function push(array $row): void
    {
        $this->rows[] = $row;
        $size = count($this->rows);

        if ($size >= self::HARD_LIMIT || ($size >= $this->flushAt && ! $this->calls->inTransaction())) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ($this->rows === []) {
            return;
        }

        // Cleared first: keeping rows after a failed write would grow without limit.
        $rows = $this->rows;
        $this->rows = [];

        try {
            $this->calls->insert($rows);
        } catch (Throwable $exception) {
            $this->warnOnce($exception);
        }
    }

    /**
     * Logged once per process so a missing table can't flood the logs.
     */
    public function warnOnce(Throwable $exception): void
    {
        if ($this->warned) {
            return;
        }

        $this->warned = true;
        $this->log->warning('HTTP Call Monitor could not record calls: '.$exception->getMessage());
    }
}
