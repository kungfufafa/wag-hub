<?php

namespace App\Http\Middleware;

use App\Models\ApiCredential;
use App\Models\ClientApplication;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuthenticateApiCredential
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('request_id', $this->requestId($request));

        $token = $request->bearerToken();

        if (! is_string($token) || trim($token) === '') {
            return $this->unauthenticated($request);
        }

        $credential = ApiCredential::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if ($credential === null || $this->isUnavailable($credential)) {
            return $this->unauthenticated($request);
        }

        $application = ClientApplication::query()
            ->whereKey($credential->client_application_id)
            ->where('is_active', true)
            ->first();

        if ($application === null) {
            return $this->unauthenticated($request);
        }

        $credential->forceFill(['last_used_at' => now()])->saveQuietly();

        $request->attributes->set('api_credential', $credential);
        $request->attributes->set('client_application', $application);

        return $next($request);
    }

    private function isUnavailable(ApiCredential $credential): bool
    {
        try {
            if ($credential->revoked_at !== null) {
                return true;
            }

            if ($credential->expires_at === null) {
                return false;
            }

            return CarbonImmutable::parse($credential->expires_at)->isPast();
        } catch (Throwable) {
            return true;
        }
    }

    private function requestId(Request $request): string
    {
        $provided = trim((string) $request->header('X-Correlation-ID', ''));

        if ($provided !== '' && preg_match('/\A[A-Za-z0-9._:-]{1,160}\z/', $provided) === 1) {
            return $provided;
        }

        return (string) Str::uuid();
    }

    private function unauthenticated(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Unauthenticated.',
            'error' => [
                'code' => 'unauthenticated',
                'retryable' => false,
            ],
            'request_id' => $request->attributes->get('request_id'),
        ], 401);
    }
}
