<?php

namespace App\Console\Commands;

use App\Models\ClientApplication;
use App\Services\Connections\ConnectionResolver;
use Illuminate\Console\Command;

class SyncWhatsAppConnectionsCommand extends Command
{
    protected $signature = 'gateway:sync-connections';

    protected $description = 'Project existing engine sessions and default routes into WhatsApp connections.';

    public function handle(ConnectionResolver $resolver): int
    {
        $count = 0;

        ClientApplication::query()->each(function (ClientApplication $application) use ($resolver, &$count): void {
            $count += count($resolver->list($application));
        });

        $this->info("Synchronized {$count} WhatsApp connections.");

        return self::SUCCESS;
    }
}
