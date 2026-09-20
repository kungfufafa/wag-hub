<?php

namespace App\Console\Commands;

use App\Models\ClientApplication;
use App\Models\EngineSession;
use App\Services\Engine\SessionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EngineGrantCapacity extends Command
{
    protected $signature = 'engine:grant-capacity {application : Client application slug} {ceiling : Maximum session slots} {--limit= : Configured operating limit}';

    protected $description = 'Grant an installation its account ceiling without disconnecting existing sessions.';

    public function handle(SessionService $sessions): int
    {
        $application = ClientApplication::query()->where('slug', $this->argument('application'))->firstOrFail();
        $ceiling = filter_var($this->argument('ceiling'), FILTER_VALIDATE_INT);
        $limit = $this->option('limit') === null ? null : filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($ceiling === false || $ceiling < 0 || $limit === false || ($limit !== null && ($limit < 0 || $limit > $ceiling))) {
            $this->error('Limits must be non-negative integers; the operating limit cannot exceed the ceiling.');

            return self::FAILURE;
        }

        return DB::transaction(function () use ($application, $sessions, $ceiling, $limit): int {
            $integration = $sessions->integration($application);
            $used = EngineSession::query()->where('client_application_id', $application->id)->count();
            $configured = $limit ?? min($integration->configured_limit, $ceiling);
            if ($used > min($configured, $ceiling)) {
                $this->error('Remove accounts before reducing the allowance below current usage.');

                return self::FAILURE;
            }
            $integration->update(['configured_limit' => $configured, 'maximum_allowed' => $ceiling]);
            $this->info('Installation capacity updated.');

            return self::SUCCESS;
        });
    }
}
