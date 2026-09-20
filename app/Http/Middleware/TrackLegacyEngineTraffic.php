<?php

namespace App\Http\Middleware;

use App\Models\ClientApplication;
use App\Services\Engine\SessionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackLegacyEngineTraffic
{
    public function handle(Request $request, Closure $next): Response
    {
        $application = $request->attributes->get('client_application');
        if ($application instanceof ClientApplication && ($request->is('api/v1/*') || $request->is('engine/*'))) {
            $integration = app(SessionService::class)->integration($application);
            if ($integration->legacy_retired_at !== null) {
                return response()->json(['message' => 'This installation has migrated to API v2.', 'error' => ['code' => 'legacy_retired', 'retryable' => false]], 410);
            }
            $integration->update(['last_legacy_request_at' => now()]);
        }

        return $next($request);
    }
}
