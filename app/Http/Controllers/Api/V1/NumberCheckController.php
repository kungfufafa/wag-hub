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
        $result = $this->checker->check($application, $recipient);
        $requestId = (string) $request->attributes->get('request_id');

        if ($result === null) {
            return response()->json([
                'message' => 'No active provider is available for number checking.',
                'error' => [
                    'code' => 'provider_unavailable',
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
                ...$result,
            ],
            'request_id' => $requestId,
        ]);
    }
}
