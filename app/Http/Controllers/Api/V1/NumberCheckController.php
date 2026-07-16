<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckWhatsAppNumberRequest;
use App\Models\ClientApplication;
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
        $routeKey = $request->routeKey();
        $result = $this->checker->check($application, $recipient, $routeKey);
        $requestId = (string) $request->attributes->get('request_id');

        if (isset($result['error_code'])) {
            $errorCode = (string) $result['error_code'];

            return response()->json([
                'message' => $errorCode === 'route_unavailable'
                    ? 'No active number-check route is available.'
                    : 'No usable provider is available for number checking.',
                'error' => [
                    'code' => $errorCode,
                    'retryable' => true,
                ],
                'request_id' => $requestId,
            ], 503);
        }

        return response()->json([
            'data' => [
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
