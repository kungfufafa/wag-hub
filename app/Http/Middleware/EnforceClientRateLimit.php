<?php

namespace App\Http\Middleware;

use App\Models\ClientApplication;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class EnforceClientRateLimit
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $application = $request->attributes->get('client_application');

        if (! $application instanceof ClientApplication) {
            return $this->rateLimited($request, 60);
        }

        $maximumAttempts = max(1, min(6000, (int) $application->rate_limit_per_minute));
        $key = "gateway-api:application:{$application->getKey()}";

        $response = RateLimiter::attempt(
            $key,
            $maximumAttempts,
            fn (): Response => $next($request),
            60,
        );

        if ($response === false) {
            return $this->rateLimited($request, RateLimiter::availableIn($key));
        }

        $response->headers->set('X-RateLimit-Limit', (string) $maximumAttempts);
        $response->headers->set(
            'X-RateLimit-Remaining',
            (string) RateLimiter::remaining($key, $maximumAttempts),
        );

        return $response;
    }

    private function rateLimited(Request $request, int $retryAfter): JsonResponse
    {
        $retryAfter = max(1, $retryAfter);

        return response()->json([
            'message' => 'Too many gateway requests for this application.',
            'error' => [
                'code' => 'rate_limited',
                'retryable' => true,
            ],
            'request_id' => $request->attributes->get('request_id'),
        ], 429, [
            'Retry-After' => (string) $retryAfter,
        ]);
    }
}
