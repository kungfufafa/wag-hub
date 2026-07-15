<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMessageRequest;
use App\Jobs\DispatchGatewayMessage;
use App\Models\ClientApplication;
use App\Models\GatewayMessage;
use App\Models\MessageEvent;
use App\Services\GatewayMessageDispatcher;
use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MessageController extends Controller
{
    public function store(StoreMessageRequest $request): JsonResponse
    {
        /** @var ClientApplication $application */
        $application = $request->attributes->get('client_application');
        $payloadHash = $request->payloadHash();

        $existing = $this->findExisting(
            applicationId: $application->getKey(),
            idempotencyKey: $request->idempotencyKey(),
        );

        if ($existing !== null) {
            return $this->idempotentResponse($request, $existing, $payloadHash);
        }

        try {
            $message = DB::transaction(function () use ($request, $application, $payloadHash): GatewayMessage {
                $message = $this->createMessage($request, $application, $payloadHash);
                $this->recordIngressEvent($message);

                if ($this->statusValue($message->mode) === 'async') {
                    DispatchGatewayMessage::dispatch($message->getKey())->afterCommit();
                }

                return $message;
            });
        } catch (QueryException $exception) {
            $winner = $this->findExisting(
                applicationId: $application->getKey(),
                idempotencyKey: $request->idempotencyKey(),
            );

            if ($winner === null) {
                throw $exception;
            }

            return $this->idempotentResponse($request, $winner, $payloadHash);
        }

        if ($this->statusValue($message->mode) === 'async') {
            return response()->json([
                'data' => $this->messageData($message, false),
                'request_id' => $this->requestId($request),
            ], 202);
        }

        $dispatched = app(GatewayMessageDispatcher::class)->dispatch($message);
        $message = $dispatched->fresh() ?? $dispatched;

        if ($this->statusValue($message->status) === 'provider_accepted') {
            return response()->json([
                'data' => $this->messageData($message, false),
                'request_id' => $this->requestId($request),
            ], 201);
        }

        return $this->dispatchFailureResponse($request, $message);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        /** @var ClientApplication $application */
        $application = $request->attributes->get('client_application');

        $message = GatewayMessage::query()
            ->where('client_application_id', $application->getKey())
            ->where('uuid', $uuid)
            ->first();

        if ($message === null) {
            return response()->json([
                'message' => 'Message not found.',
                'error' => [
                    'code' => 'not_found',
                    'retryable' => false,
                ],
                'request_id' => $this->requestId($request),
            ], 404);
        }

        return response()->json([
            'data' => $this->messageData($message),
            'request_id' => $this->requestId($request),
        ]);
    }

    private function createMessage(
        StoreMessageRequest $request,
        ClientApplication $application,
        string $payloadHash,
    ): GatewayMessage {
        $payload = $request->canonicalPayload();
        $recipient = $request->canonicalRecipient();
        $mode = (string) $payload['mode'];
        $now = now();

        $message = new GatewayMessage;
        $message->forceFill([
            'uuid' => (string) Str::uuid(),
            'client_application_id' => $application->getKey(),
            'routing_policy_id' => null,
            'accepted_provider_account_id' => null,
            'idempotency_key' => $request->idempotencyKey(),
            'payload_hash' => $payloadHash,
            'correlation_id' => $this->requestId($request),
            'client_reference' => $payload['client_reference'],
            'recipient' => $recipient,
            'recipient_hash' => hash_hmac('sha256', $recipient, (string) config('app.key')),
            'recipient_last4' => substr($recipient, -4),
            'body' => $payload['message']['text'],
            'purpose' => $payload['purpose'],
            'route_key' => $payload['route_key'],
            'mode' => $mode,
            'priority' => $this->priorityFor((string) $payload['purpose']),
            'status' => $mode === 'async' ? 'queued' : 'processing',
            'metadata' => $payload['metadata'],
            'expires_at' => is_string($payload['expires_at'])
                ? CarbonImmutable::parse($payload['expires_at'])
                : null,
            'queued_at' => $mode === 'async' ? $now : null,
            'processing_at' => $mode === 'sync' ? $now : null,
        ]);
        $message->save();

        return $message;
    }

    private function recordIngressEvent(GatewayMessage $message): void
    {
        $mode = $this->statusValue($message->mode);
        $event = new MessageEvent;
        $event->forceFill([
            'gateway_message_id' => $message->getKey(),
            'type' => $mode === 'async' ? 'queued' : 'processing',
            'source' => 'api',
            'data' => null,
            'occurred_at' => now(),
        ]);
        $event->save();
    }

    private function idempotentResponse(
        StoreMessageRequest $request,
        GatewayMessage $message,
        string $payloadHash,
    ): JsonResponse {
        if (! hash_equals((string) $message->payload_hash, $payloadHash)) {
            return response()->json([
                'message' => 'The idempotency key was already used with a different payload.',
                'error' => [
                    'code' => 'idempotency_conflict',
                    'retryable' => false,
                    'original_message_id' => $message->uuid,
                ],
                'request_id' => $this->requestId($request),
            ], 409);
        }

        return response()->json([
            'data' => $this->messageData($message, true),
            'request_id' => $this->requestId($request),
        ]);
    }

    private function dispatchFailureResponse(Request $request, GatewayMessage $message): JsonResponse
    {
        $status = $this->statusValue($message->status);

        [$httpStatus, $code, $retryable] = match ($status) {
            'outcome_unknown' => [502, 'provider_outcome_unknown', false],
            'expired' => [410, 'message_expired', false],
            'failed', 'dead_letter' => [
                503,
                in_array($message->last_error_code, ['route_unavailable', 'providers_failed'], true)
                    ? $message->last_error_code
                    : 'providers_failed',
                true,
            ],
            default => [503, 'gateway_dispatch_incomplete', true],
        };

        return response()->json([
            'message' => match ($code) {
                'provider_outcome_unknown' => 'The provider outcome could not be determined.',
                'message_expired' => 'The message expired before it could be sent.',
                'route_unavailable' => 'No usable route is currently available.',
                default => 'No provider accepted the message.',
            },
            'error' => [
                'code' => $code,
                'retryable' => $retryable,
            ],
            'data' => $this->messageData($message),
            'request_id' => $this->requestId($request),
        ], $httpStatus);
    }

    private function findExisting(int $applicationId, string $idempotencyKey): ?GatewayMessage
    {
        return GatewayMessage::query()
            ->where('client_application_id', $applicationId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function messageData(GatewayMessage $message, ?bool $duplicate = null): array
    {
        $data = [
            'id' => (string) $message->uuid,
            'status' => $this->statusValue($message->status),
            'mode' => $this->statusValue($message->mode),
            'purpose' => $this->statusValue($message->purpose),
            'route_key' => (string) $message->route_key,
            'client_reference' => $message->client_reference,
            'provider_message_id' => $message->provider_message_id,
            'created_at' => $this->iso8601($message->created_at),
            'queued_at' => $this->iso8601($message->queued_at),
            'processing_at' => $this->iso8601($message->processing_at),
            'provider_accepted_at' => $this->iso8601($message->provider_accepted_at),
            'failed_at' => $this->iso8601($message->failed_at),
            'outcome_unknown_at' => $this->iso8601($message->outcome_unknown_at),
            'expires_at' => $this->iso8601($message->expires_at),
        ];

        if ($duplicate !== null) {
            $data['duplicate'] = $duplicate;
        }

        if ($message->accepted_provider_account_id !== null) {
            $data['provider'] = DB::table('provider_accounts')
                ->where('id', $message->accepted_provider_account_id)
                ->value('slug');
        }

        return $data;
    }

    private function priorityFor(string $purpose): int
    {
        return match ($purpose) {
            'otp' => 100,
            'transactional' => 50,
            default => 10,
        };
    }

    private function requestId(Request $request): string
    {
        return (string) $request->attributes->get('request_id');
    }

    private function statusValue(mixed $value): string
    {
        return $value instanceof BackedEnum ? (string) $value->value : (string) $value;
    }

    private function iso8601(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->toIso8601String();
        }

        return CarbonImmutable::parse($value)->toIso8601String();
    }
}
