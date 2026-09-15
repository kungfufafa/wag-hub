<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Delivery\OutboundAttachment;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMessageRequest;
use App\Models\Attachment;
use App\Models\ClientApplication;
use App\Models\GatewayMessage;
use App\Models\MessageEvent;
use App\Services\AttachmentService;
use App\Services\GatewayMessageDispatcher;
use App\Services\GatewayMessageEnqueuer;
use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MessageController extends Controller
{
    public function __construct(private readonly GatewayMessageEnqueuer $enqueuer) {}

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

                return $message;
            });
        } catch (UniqueConstraintViolationException $exception) {
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
            $enqueueFailure = $this->enqueueAsyncMessage($request, $message, false);

            if ($enqueueFailure !== null) {
                return $enqueueFailure;
            }

            $message->refresh();

            if (! $this->enqueuer->usesAsyncDispatch()) {
                return $this->inlineDispatchResponse($request, $message, false);
            }

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
        $attachment = $request->outboundAttachment();

        if ($attachment?->attachmentId !== null) {
            app(AttachmentService::class)->markReferenced($attachment->attachmentId);
        }

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
            'body' => $request->messageText(),
            'message_type' => $request->messageType(),
            'attachment' => $attachment?->toArray(),
            'purpose' => $payload['purpose'],
            'route_key' => $payload['route_key'],
            'mode' => $mode,
            'origin' => 'api',
            'priority' => $this->priorityFor((string) $payload['purpose']),
            'status' => $mode === 'async' ? 'queued' : 'processing',
            'metadata' => $payload['metadata'],
            'expires_at' => is_string($payload['expires_at'])
                ? CarbonImmutable::parse($payload['expires_at'])->setTimezone(config('app.timezone'))
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

        if ($this->enqueuer->hasUnrecoveredFailure($message)) {
            $enqueueFailure = $this->enqueueAsyncMessage($request, $message, true);

            if ($enqueueFailure !== null) {
                return $enqueueFailure;
            }

            $message->refresh();

            if (! $this->enqueuer->usesAsyncDispatch()) {
                return $this->inlineDispatchResponse($request, $message, true);
            }
        }

        return response()->json([
            'data' => $this->messageData($message, true),
            'request_id' => $this->requestId($request),
        ]);
    }

    private function enqueueAsyncMessage(
        Request $request,
        GatewayMessage $message,
        bool $duplicate,
    ): ?JsonResponse {
        if (! $this->enqueuer->enqueue($message, 'api')) {
            $message->refresh();

            if (! $this->enqueuer->usesAsyncDispatch()
                && $this->statusValue($message->status) !== 'queued') {
                return $this->dispatchFailureResponse($request, $message);
            }

            return response()->json([
                'message' => 'The message was saved, but the queue is currently unavailable.',
                'error' => [
                    'code' => 'queue_unavailable',
                    'retryable' => true,
                ],
                'data' => $this->messageData($message, $duplicate),
                'request_id' => $this->requestId($request),
            ], 503);
        }

        return null;
    }

    private function inlineDispatchResponse(
        Request $request,
        GatewayMessage $message,
        bool $duplicate,
    ): JsonResponse {
        if ($this->statusValue($message->status) === 'provider_accepted') {
            return response()->json([
                'data' => $this->messageData($message, $duplicate),
                'request_id' => $this->requestId($request),
            ], 201);
        }

        if ($this->statusValue($message->status) === 'queued') {
            return response()->json([
                'message' => 'The message was saved, but the queue is currently unavailable.',
                'error' => [
                    'code' => 'queue_unavailable',
                    'retryable' => true,
                ],
                'data' => $this->messageData($message, $duplicate),
                'request_id' => $this->requestId($request),
            ], 503);
        }

        return $this->dispatchFailureResponse($request, $message);
    }

    private function dispatchFailureResponse(Request $request, GatewayMessage $message): JsonResponse
    {
        $status = $this->statusValue($message->status);
        $attachmentError = in_array($message->last_error_code, [
            'attachment_unavailable',
            'attachment_invalid',
            'attachment_format_unsupported',
            'attachment_size_unsupported',
        ], true);

        [$httpStatus, $code, $retryable] = match ($status) {
            'outcome_unknown' => [502, 'provider_outcome_unknown', false],
            'expired' => [410, 'message_expired', false],
            'failed', 'dead_letter' => $attachmentError
                ? [422, (string) $message->last_error_code, false]
                : [
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
                'attachment_unavailable' => 'Attachment tidak tersedia untuk dikirim.',
                'attachment_invalid' => 'Attachment tidak valid untuk dikirim.',
                'attachment_format_unsupported' => 'Format attachment tidak didukung provider.',
                'attachment_size_unsupported' => 'Ukuran attachment melebihi batas provider.',
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
            'message_type' => (string) ($message->message_type ?: 'text'),
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

        $attachment = $message->outboundAttachment();

        if ($attachment !== null) {
            $data['attachment'] = $this->attachmentData($message, $attachment);
        }

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

    /** @return array<string, mixed> */
    private function attachmentData(GatewayMessage $message, OutboundAttachment $attachment): array
    {
        if ($attachment->attachmentId === null) {
            return [
                'kind' => $attachment->kind->value,
                'url' => $attachment->url,
                'filename' => $attachment->filename,
                'mime_type' => $attachment->mimeType,
            ];
        }

        $stored = Attachment::query()
            ->where('uuid', $attachment->attachmentId)
            ->where('client_application_id', $message->client_application_id)
            ->first();

        if ($stored === null) {
            return [
                'id' => $attachment->attachmentId,
                'kind' => $attachment->kind->value,
                'status' => 'expired',
                'filename' => $attachment->filename,
                'mime_type' => $attachment->mimeType,
                'download_url' => null,
            ];
        }

        return [
            'id' => (string) $stored->uuid,
            'kind' => (string) $stored->media_kind,
            'status' => $stored->isAvailable() ? (string) $stored->status : 'expired',
            'filename' => (string) $stored->original_filename,
            'mime_type' => (string) $stored->mime_type,
            'size' => (int) $stored->size,
            'download_url' => $stored->isAvailable()
                ? app(AttachmentService::class)->temporaryUrl($stored)
                : null,
        ];
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
