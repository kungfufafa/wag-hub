<?php

namespace App\Console\Commands;

use App\Models\ClientApplication;
use App\Models\EngineSession;
use App\Models\ProviderAccount;
use App\Services\Engine\SessionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EngineMigrateLegacy extends Command
{
    protected $signature = 'engine:migrate-legacy {--application= : Only migrate this client application slug} {--dry-run}';

    protected $description = 'Map existing native engine accounts into v2 without changing their runner identity or sending messages.';

    public function handle(SessionService $sessions): int
    {
        $count = 0;
        foreach (ProviderAccount::query()->where('driver', 'wag_hub')->cursor() as $provider) {
            $configuration = $provider->configuration ?? [];
            $owner = $configuration['owned_by_application_id'] ?? null;
            $reference = $configuration['engine_session_id'] ?? $configuration['cesa_session_id'] ?? null;
            $application = $owner ? ClientApplication::query()->find($owner) : null;
            if (! $application || ! is_string($reference) || $reference === '' || ($this->option('application') && $application->slug !== $this->option('application'))) {
                continue;
            }
            if ($this->option('dry-run')) {
                $count++;

                continue;
            }
            DB::transaction(function () use ($sessions, $application, $provider, $reference): void {
                $integration = $sessions->integration($application);
                $session = EngineSession::withTrashed()->where('client_application_id', $application->id)->where('external_reference', $reference)->first();
                if ($session) {
                    return;
                }
                $used = EngineSession::query()->where('client_application_id', $application->id)->count();
                if ($used >= $integration->maximum_allowed) {
                    throw new \RuntimeException('Grant sufficient capacity before migrating '.$application->slug.'.');
                }
                $integration->update(['configured_limit' => max($used + 1, $integration->configured_limit)]);
                EngineSession::query()->create([
                    'client_application_id' => $application->id, 'runner_id' => 'wgh-'.$provider->uuid,
                    'name' => $provider->name, 'external_reference' => $reference,
                    'creation_key' => 'legacy:'.$provider->uuid, 'creation_hash' => hash('sha256', 'legacy:'.$provider->uuid),
                    'desired_state' => $provider->is_active ? 'RUNNING' : 'STOPPED',
                    'status' => 'STOPPED', 'metadata' => ['legacy_provider_id' => $provider->id],
                ]);
            });
            $count++;
        }
        $this->info(($this->option('dry-run') ? 'Eligible' : 'Mapped').' native accounts: '.$count);

        return self::SUCCESS;
    }
}
