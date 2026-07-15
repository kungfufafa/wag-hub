<?php

namespace App\Console\Commands;

use App\Services\StaleGatewayMessageRecovery;
use Illuminate\Console\Command;

class RecoverStaleGatewayMessages extends Command
{
    protected $signature = 'gateway:recover-stale
        {--minutes=10 : Processing age in minutes before a message is stale}
        {--limit=100 : Maximum messages reconciled in one run}';

    protected $description = 'Safely reconcile stale deliveries and retry failed queue handoffs';

    public function handle(StaleGatewayMessageRecovery $recovery): int
    {
        $minutes = filter_var(
            $this->option('minutes'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 1440]],
        );
        $limit = filter_var(
            $this->option('limit'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 1000]],
        );

        if ($minutes === false || $limit === false) {
            $this->error('Options --minutes and --limit must be positive integers within their safe bounds.');

            return self::FAILURE;
        }

        $summary = $recovery->recover($minutes, $limit);

        $this->info("Examined: {$summary['examined']}");
        $this->line("Requeued: {$summary['requeued']}");
        $this->line("Enqueue failed: {$summary['enqueue_failed']}");
        $this->line("Accepted aggregates restored: {$summary['accepted']}");
        $this->line("Outcome unknown: {$summary['outcome_unknown']}");
        $this->line("Failed aggregates restored: {$summary['failed']}");

        return $summary['enqueue_failed'] > 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
