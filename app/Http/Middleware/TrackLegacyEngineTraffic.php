<?php

namespace App\Http\Middleware;

use App\Models\ClientApplication;
use App\Models\EngineIntegration;
use App\Services\Engine\SessionService;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackLegacyEngineTraffic
{
    // Retirement needs seven quiet days, so minute-level freshness is enough.
    private const TOUCH_INTERVAL_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $application = $request->attributes->get('client_application');
        if ($application instanceof ClientApplication && ($request->is('api/v1/*') || $request->is('engine/*'))) {
            // Plain read: the locked create path in SessionService is only needed for the first request.
            $integration = EngineIntegration::query()->where('client_application_id', $application->id)->first()
                ?? app(SessionService::class)->integration($application);
            if ($integration->legacy_retired_at !== null) {
                return response()->json(['message' => 'This installation has migrated to API v2.', 'error' => ['code' => 'legacy_retired', 'retryable' => false]], 410);
            }
            if ($integration->last_legacy_request_at === null
                || $integration->last_legacy_request_at->lt(now()->subSeconds(self::TOUCH_INTERVAL_SECONDS))) {
                EngineIntegration::query()->whereKey($integration->id)
                    ->where(fn (Builder $query) => $query->whereNull('last_legacy_request_at')
                        ->orWhere('last_legacy_request_at', '<', now()->subSeconds(self::TOUCH_INTERVAL_SECONDS)))
                    ->update(['last_legacy_request_at' => now()]);
            }
        }

        return $next($request);
    }
}
