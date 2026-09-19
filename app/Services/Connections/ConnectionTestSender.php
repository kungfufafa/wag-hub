<?php

namespace App\Services\Connections;

use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Requests\StoreMessageRequest;
use App\Models\ClientApplication;
use App\Models\WhatsAppConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

final class ConnectionTestSender
{
    public function send(WhatsAppConnection $connection, string $recipient, string $text): JsonResponse
    {
        $application = $connection->clientApplication
            ?? ClientApplication::query()->findOrFail($connection->client_application_id);

        $payload = [
            'connection_id' => $connection->uuid,
            'recipient' => ['type' => 'phone', 'value' => $recipient],
            'message' => ['type' => 'text', 'text' => $text],
            'purpose' => 'notification',
            'mode' => 'sync',
        ];

        $request = StoreMessageRequest::create(
            '/api/v1/messages',
            'POST',
            $payload,
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_IDEMPOTENCY_KEY' => 'conn-test-'.(string) Str::uuid(),
            ],
        );
        $request->attributes->set('client_application', $application);
        $request->attributes->set('request_id', (string) Str::uuid());
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        return app(MessageController::class)->store($request);
    }
}
