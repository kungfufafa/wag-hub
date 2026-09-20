<?php

namespace App\Console\Commands;

use App\Models\ClientApplication;
use App\Models\EngineIntegration;
use App\Services\Engine\SessionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EngineLegacyRetirement extends Command
{
    protected $signature = 'engine:legacy-retirement {--verify= : Mark a client application slug as tested on v2} {--retire : Disable legacy access after all active installations pass the seven-day quiet period}';

    protected $description = 'Record verified consumer migration and safely gate legacy API retirement.';

    public function handle(SessionService $sessions): int
    {
        if ($slug = $this->option('verify')) {
            $application = ClientApplication::query()->where('slug', $slug)->firstOrFail();
            $sessions->integration($application)->update(['migration_verified_at' => now()]);
            $this->info('Migration verification recorded; a seven-day quiet period is still required.');
        }

        return DB::transaction(function () use ($sessions): int {
            $applications = ClientApplication::query()->where('is_active', true)->orderBy('id')->lockForUpdate()->get();
            $eligible = [];
            $blocked = [];
            foreach ($applications as $application) {
                $integration = $sessions->integration($application);
                if ($integration->legacy_retired_at !== null) {
                    continue;
                }
                if ($integration->migration_verified_at === null || $integration->migration_verified_at->gt(now()->subDays(7))
                    || $integration->last_legacy_request_at?->gt(now()->subDays(7))) {
                    $blocked[] = $application->slug;
                } else {
                    $eligible[] = $integration->id;
                }
            }
            if ($blocked !== []) {
                $this->warn('Waiting for verified migration and seven quiet days: '.implode(', ', $blocked));

                return $this->option('retire') ? self::FAILURE : self::SUCCESS;
            }
            if ($this->option('retire')) {
                EngineIntegration::query()->whereIn('id', $eligible)->update(['legacy_retired_at' => now()]);
                $this->info('Legacy access retired. Historical records remain readable by administrators.');
            } else {
                $this->info('All active installations are eligible for legacy retirement.');
            }

            return self::SUCCESS;
        });
    }
}
