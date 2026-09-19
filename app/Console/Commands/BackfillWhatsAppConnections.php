<?php

namespace App\Console\Commands;

use App\Services\Connection\ConnectionBackfillService;
use Illuminate\Console\Command;

class BackfillWhatsAppConnections extends Command
{
    protected $signature = 'gateway:backfill-connections
        {--application= : Limit to one client application id}
        {--dry-run : Report changes without writing}';

    protected $description = 'Create WhatsApp Connection records from existing provider accounts and routing policies';

    public function handle(ConnectionBackfillService $backfill): int
    {
        $applicationId = $this->option('application');
        $dryRun = (bool) $this->option('dry-run');

        if ($applicationId !== null && ! is_numeric($applicationId)) {
            $this->error('Option --application must be a numeric client application id.');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('Dry run — no database writes.');
        }

        $summary = $backfill->backfill(
            applicationId: $applicationId !== null ? (int) $applicationId : null,
            dryRun: $dryRun,
        );

        $this->info("Created: {$summary['created']}");
        $this->line("Updated: {$summary['updated']}");
        $this->line("Skipped: {$summary['skipped']}");

        return self::SUCCESS;
    }
}
