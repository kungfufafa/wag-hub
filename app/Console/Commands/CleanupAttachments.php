<?php

namespace App\Console\Commands;

use App\Services\AttachmentService;
use Illuminate\Console\Command;

class CleanupAttachments extends Command
{
    protected $signature = 'gateway:cleanup-attachments';

    protected $description = 'Hapus file attachment orphan atau yang sudah melewati retensi.';

    public function handle(AttachmentService $attachments): int
    {
        $count = $attachments->cleanup();
        $this->info("{$count} attachment dibersihkan.");

        return self::SUCCESS;
    }
}
