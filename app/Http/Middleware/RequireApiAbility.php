<?php

namespace App\Http\Middleware;

use App\Models\ApiCredential;
use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireApiAbility
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $requiredAbility): Response
    {
        $credential = $request->attributes->get('api_credential');

        if (! $credential instanceof ApiCredential) {
            return $this->forbidden($request);
        }

        $abilities = $credential->abilities;

        if ($abilities instanceof Arrayable) {
            $abilities = $abilities->toArray();
        } elseif (is_string($abilities)) {
            $decoded = json_decode($abilities, true);
            $abilities = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($abilities) || (! in_array('*', $abilities, true) && ! in_array($requiredAbility, $abilities, true))) {
            return $this->forbidden($request);
        }

        return $next($request);
    }

    private function forbidden(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'This credential is not allowed to perform this action.',
            'error' => [
                'code' => 'forbidden',
                'retryable' => false,
            ],
            'request_id' => $request->attributes->get('request_id'),
        ], 403);
    }
}
