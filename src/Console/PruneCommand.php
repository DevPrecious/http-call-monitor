<?php

namespace HttpCallMonitor\Console;

use HttpCallMonitor\CallRepository;
use Illuminate\Console\Command;

final class PruneCommand extends Command
{
    protected $signature = 'http-call-monitor:prune {--days= : Delete calls older than this many days (defaults to the configured retention)}';

    protected $description = 'Delete old recorded HTTP calls';

    public function handle(CallRepository $calls): int
    {
        $days = $this->option('days') ?? config('http-call-monitor.retention_days');

        if ($days === null) {
            $this->info('Retention is disabled, so nothing was deleted.');

            return self::SUCCESS;
        }

        if ((int) $days < 1) {
            $this->error('--days must be a whole number of at least 1.');

            return self::INVALID;
        }

        $deleted = $calls->prune((int) $days);
        $this->info("Deleted {$deleted} recorded call(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
