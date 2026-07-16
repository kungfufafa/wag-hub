<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckWhatsAppNumberRequest;
use App\Models\ApiCredential;
use App\Models\ClientApplication;
use App\Models\NumberCheckRequest;
use App\Services\WhatsAppNumberChecker;
use Illuminate\Http\JsonResponse;

final class NumberCheckController extends Controller
{
    public function __construct(private readonly WhatsAppNumberChecker $checker) {}

    public function store(CheckWhatsAppNumberRequest $request): JsonResponse
    {
        $recipient = $request->canonicalRecipient();
        /** @var ClientApplication $application */
        $application = $request->attributes->get('client_application');
        /** @var ApiCredential $credential */
        $credential = $request->attributes->get('api_credential');
        $routeKey = $request->routeKey();
        $requestId = (string) $request->attributes->get('request_id');
        $audit = NumberCheckRequest::forceCreate([
            'client_application_id' => $application->getKey(),
            'api_credential_id' => $credential->getKey(),
            'correlation_id' => $requestId,
            'recipient' => $recipient,
            'recipient_hash' => hash_hmac('sha256', $recipient, (string) config('app.key')),
            'recipient_last4' => substr($recipient, -4),
            'route_key' => $routeKey,
            'status' => 'processing',
            'started_at' => now(),
        ]);
        $result = $this->checker->check($application, $recipient, $routeKey, $audit);

        if (isset($result['error_code'])) {
            $errorCode = (string) $result['error_code'];

            return response()->json([
                'message' => $errorCode === 'route_unavailable'
                    ? 'No active number-check route is available.'
                    : 'No usable provider is available for number checking.',
                'error' => [
                    'code' => $errorCode,
                    'retryable' => true,
                    'audit_id' => (string) $audit->uuid,
                ],
                'request_id' => $requestId,
            ], 503);
        }

        return response()->json([
            'data' => [
                'id' => (string) $audit->uuid,
                'recipient' => [
                    'type' => 'phone',
                    'value' => $recipient,
                ],
                'route_key' => $routeKey,
                ...$result,
            ],
            'request_id' => $requestId,
        ]);
    }
}
