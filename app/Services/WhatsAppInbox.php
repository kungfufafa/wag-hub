<?php

namespace App\Services;

use App\Contracts\WhatsApp\ProviderInboxReader;
use App\Domain\Delivery\OutboundAttachment;
use App\Domain\Delivery\ProviderResult;
use App\Domain\Inbox\InboxDispatch;
use App\Domain\Inbox\InboxEvent;
use App\Infrastructure\WhatsApp\GowaInboxReader;
use App\Infrastructure\WhatsApp\InboxPayload;
use App\Infrastructure\WhatsApp\WahaInboxReader;
use App\Models\Attachment;
use App\Models\GatewayMessage;
use App\Models\ProviderAccount;
use App\Support\PhoneNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final readonly class WhatsAppInbox
{
    public function __construct(
        private WahaInboxReader $waha,
        private GowaInboxReader $gowa,
        private PhoneNormalizer $phones,
        private InboxLedger $ledger,
        private AttachmentService $attachments,
        private GatewayMessageEnqueuer $enqueuer,
    ) {}

    /**
     * @return Collection<int, ProviderAccount>
     */
    public function channels(): Collection
    {
        return ProviderAccount::query()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();
    }

    public function webhookUrl(ProviderAccount $account): string
    {
        return url('/webhooks/whatsapp/'.$account->uuid);
    }

    public function driverLabel(string $driver): string
    {
        return match ($driver) {
            'waha' => 'WAHA',
            'gowa' => 'GOWA',
            'fonnte' => 'Fonnte',
            'waba' => 'WABA',
            default => strtoupper($driver),
        };
    }

    /**
     * @return list<array{id: string, title: string, preview: string, timestamp: ?string, is_group: bool, last_from_me: ?bool, provider_id: ?int, provider_name: ?string, driver: ?string}>
     */
    public function chats(?string $search = null, ?ProviderAccount $account = null): array
    {
        $accounts = $account === null ? $this->channels() : collect([$account]);

        foreach ($accounts as $item) {
            $this->pullChats($item, $search);
        }

        return $this->ledger->chats($account, $search);
    }

    /**
     * @return list<array{id: string, body: string, from_me: bool, timestamp: ?string, kind: string}>
     */
    public function messages(ProviderAccount $account, string $chatId): array
    {
        if ($chatId === '') {
            return [];
        }

        $this->pullMessages($account, $chatId);

        return $this->ledger->messages($account, $chatId);
    }

    public function send(
        ProviderAccount $account,
        string $target,
        string $body,
        ?OutboundAttachment $attachment = null,
        ?string $submissionUuid = null,
    ): InboxDispatch {
        $body = trim($body);

        if ($body === '' && $attachment === null) {
            throw new InvalidArgumentException('Isi pesan tidak boleh kosong.');
        }

        if ($attachment?->kind->supportsCaption() === false && $body !== '') {
            throw new InvalidArgumentException('Audio tidak mendukung caption.');
        }

        if ($attachment !== null && mb_strlen($body) > 1024) {
            throw new InvalidArgumentException('Caption attachment maksimal 1.024 karakter.');
        }

        if ($attachment === null && mb_strlen($body) > 10000) {
            throw new InvalidArgumentException('Isi pesan maksimal 10.000 karakter.');
        }

        $submissionUuid ??= (string) Str::uuid();

        if (! Str::isUuid($submissionUuid)) {
            throw new InvalidArgumentException('ID submission tidak valid.');
        }

        $this->assertDashboardAttachmentOwner($attachment);

        if ($attachment !== null && $attachment->attachmentId === null) {
            $this->attachments->assertPublicUrl($attachment->url);
        }

        $chatId = $this->normalizeChatId($account, $target);
        $recipient = explode('@', $chatId)[0] ?: $chatId;

        $idempotencyKey = 'inbox-'.$submissionUuid;
        $message = $this->findSubmission($submissionUuid, $idempotencyKey);
        $shouldEnqueue = false;

        if ($message !== null) {
            $storedAttachment = $message->outboundAttachment()?->toArray();
            $requestedAttachment = $attachment?->toArray();

            if ($message->inbox_chat_id !== $chatId
                || $message->plaintextBody() !== $body
                || $storedAttachment !== $requestedAttachment
                || (int) $message->pinned_provider_account_id !== (int) $account->id) {
                throw new InvalidArgumentException('ID submission sudah dipakai untuk payload lain.');
            }

            if ($message->status === 'processing') {
                return new InboxDispatch(
                    ProviderResult::outcomeUnknown(
                        errorCode: 'submission_in_progress',
                        errorMessage: 'Pengiriman dengan ID submission ini masih diproses.',
                    ),
                    $chatId,
                );
            }

            if ($message->status === 'failed' && $message->isSafeToRetry()) {
                $message->queueForRetry();
                $shouldEnqueue = true;
            }

            if ($message->status === 'queued'
                && ! $shouldEnqueue
                && ! $this->enqueuer->hasUnrecoveredFailure($message)) {
                return new InboxDispatch($this->queuedResult(), $chatId);
            }

            if ($message->status === 'queued') {
                $shouldEnqueue = true;
            }

            if (! $shouldEnqueue) {
                return new InboxDispatch($this->providerResult($message), $chatId);
            }
        } else {
            try {
                $message = $this->createGatewayMessage(
                    account: $account,
                    chatId: $chatId,
                    recipient: $recipient,
                    body: $body,
                    attachment: $attachment,
                    submissionUuid: $submissionUuid,
                );
            } catch (UniqueConstraintViolationException $exception) {
                // A concurrent double submit won the Inbox submission UUID.
                // Re-enter the idempotent path after the winner commits.
                if ($this->findSubmission($submissionUuid, $idempotencyKey) === null) {
                    throw $exception;
                }

                return $this->send($account, $target, $body, $attachment, $submissionUuid);
            }
            $shouldEnqueue = true;
        }

        $this->ledger->record($account, new InboxEvent(
            chatId: $chatId,
            messageId: 'gateway-'.$message->uuid,
            body: $body,
            fromMe: true,
            occurredAt: time(),
            kind: $attachment?->kind->value ?? 'text',
            title: $recipient,
            isGroup: InboxPayload::isGroup($chatId),
            attachment: $attachment?->toArray(),
            gatewayMessageId: (int) $message->getKey(),
        ));

        $enqueued = $this->enqueuer->enqueue($message, 'admin');
        $message->refresh();

        if (! $enqueued) {
            return new InboxDispatch(
                ProviderResult::rejected(
                    errorCode: 'queue_unavailable',
                    errorMessage: 'Pesan tersimpan, tetapi antrean pengiriman belum tersedia.',
                ),
                $chatId,
            );
        }

        if (in_array((string) $message->status, ['queued', 'processing'], true)) {
            return new InboxDispatch($this->queuedResult(), $chatId);
        }

        $result = $this->providerResult($message);

        if ($result->isAccepted()) {
            try {
                $this->ledger->record($account, new InboxEvent(
                    chatId: $chatId,
                    messageId: $result->providerMessageId ?? ('local-'.bin2hex(random_bytes(8))),
                    body: $body,
                    fromMe: true,
                    occurredAt: time(),
                    kind: $attachment?->kind->value ?? 'text',
                    title: $recipient,
                    isGroup: InboxPayload::isGroup($chatId),
                    attachment: $attachment?->toArray(),
                    gatewayMessageId: (int) $message->getKey(),
                ));
            } catch (Throwable) {
                // Provider acceptance remains authoritative if the local
                // history update is temporarily unavailable.
            }
        }

        return new InboxDispatch($result, $chatId);
    }

    private function createGatewayMessage(
        ProviderAccount $account,
        string $chatId,
        string $recipient,
        string $body,
        ?OutboundAttachment $attachment,
        string $submissionUuid,
    ): GatewayMessage {
        $uuid = (string) Str::uuid();
        if ($attachment?->attachmentId !== null) {
            $this->attachments->markReferenced($attachment->attachmentId);
        }

        $payload = [
            'recipient' => $recipient,
            'chat_id' => $chatId,
            'body' => $body,
            'attachment' => $attachment?->toArray(),
            'provider_id' => $account->id,
        ];
        $now = now();

        return DB::transaction(function () use ($account, $attachment, $body, $chatId, $now, $payload, $recipient, $submissionUuid, $uuid): GatewayMessage {
            $message = new GatewayMessage;
            $message->forceFill([
                'uuid' => $uuid,
                'client_application_id' => null,
                'routing_policy_id' => null,
                'accepted_provider_account_id' => null,
                'idempotency_key' => 'inbox-'.$submissionUuid,
                'inbox_submission_uuid' => $submissionUuid,
                'payload_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
                'correlation_id' => 'inbox-'.$uuid,
                'client_reference' => null,
                'recipient' => $recipient,
                'recipient_hash' => hash_hmac('sha256', $recipient, (string) config('app.key')),
                'recipient_last4' => substr($recipient, -4),
                'body' => $body,
                'message_type' => $attachment?->kind->value ?? 'text',
                'attachment' => $attachment?->toArray(),
                'purpose' => 'notification',
                'route_key' => 'inbox',
                'mode' => 'sync',
                'origin' => 'inbox',
                'origin_user_id' => auth()->id(),
                'pinned_provider_account_id' => $account->id,
                'inbox_chat_id' => $chatId,
                'priority' => 50,
                'status' => 'queued',
                'metadata' => ['source' => 'dashboard_inbox'],
                'queued_at' => $now,
            ])->save();

            $message->events()->create([
                'type' => 'queued',
                'source' => 'admin',
                'data' => ['source' => 'dashboard_inbox'],
                'occurred_at' => $now,
            ]);

            return $message;
        });
    }

    private function assertDashboardAttachmentOwner(?OutboundAttachment $attachment): void
    {
        $attachmentId = $attachment?->attachmentId;

        if ($attachmentId === null) {
            return;
        }

        $owned = Attachment::query()
            ->where('uuid', $attachmentId)
            ->whereNull('client_application_id')
            ->where('user_id', auth()->id())
            ->exists();

        if (! $owned) {
            throw new InvalidArgumentException('Attachment tidak dimiliki administrator yang sedang aktif.');
        }
    }

    private function findSubmission(string $submissionUuid, string $idempotencyKey): ?GatewayMessage
    {
        return GatewayMessage::query()
            ->where('origin', 'inbox')
            ->where(function ($query) use ($idempotencyKey, $submissionUuid): void {
                $query->where('inbox_submission_uuid', $submissionUuid)
                    ->orWhere(function ($legacy) use ($idempotencyKey): void {
                        $legacy->whereNull('inbox_submission_uuid')
                            ->where('idempotency_key', $idempotencyKey);
                    });
            })
            ->first();
    }

    private function providerResult(GatewayMessage $message): ProviderResult
    {
        $attempt = $message->attempts()->latest('sequence')->first();

        return match ((string) $message->status) {
            'provider_accepted' => ProviderResult::accepted(
                providerMessageId: $message->provider_message_id,
            ),
            'outcome_unknown' => ProviderResult::outcomeUnknown(
                errorCode: $message->last_error_code,
                errorMessage: $message->last_error_message,
            ),
            default => ProviderResult::rejected(
                httpStatus: $attempt?->http_status,
                errorCode: $message->last_error_code ?: $attempt?->error_code,
                errorMessage: $message->last_error_message ?: $attempt?->error_message,
            ),
        };
    }

    private function queuedResult(): ProviderResult
    {
        return ProviderResult::rejected(
            errorCode: 'message_queued',
            errorMessage: 'Pesan sudah disimpan dan menunggu worker pengiriman.',
        );
    }

    public function recordEvent(ProviderAccount $account, InboxEvent $event): void
    {
        $this->ledger->record($account, $event);
    }

    public function normalizeChatId(ProviderAccount $account, string $target): string
    {
        $target = trim($target);

        if ($target === '') {
            throw new InvalidArgumentException('Nomor tujuan tidak boleh kosong.');
        }

        if (str_contains($target, '@')) {
            return $target;
        }

        $phone = $this->phones->normalize($target);

        return match ($account->driver) {
            'waha' => $phone.'@c.us',
            'gowa' => $phone.'@s.whatsapp.net',
            default => $phone,
        };
    }

    private function pullChats(ProviderAccount $account, ?string $search): void
    {
        $reader = $this->reader($account);

        if ($reader === null) {
            return;
        }

        try {
            foreach ($reader->chats($account, $search) as $chat) {
                $this->ledger->rememberChat($account, $chat);
            }
        } catch (Throwable) {
            // Keep the local ledger when the gateway is unreachable.
        }
    }

    private function pullMessages(ProviderAccount $account, string $chatId): void
    {
        $reader = $this->reader($account);

        if ($reader === null) {
            return;
        }

        try {
            foreach ($this->messageChatIds($account, $chatId) as $candidate) {
                $pulled = $reader->messages($account, $candidate);

                foreach ($pulled as $message) {
                    $this->ledger->record($account, new InboxEvent(
                        chatId: $candidate,
                        messageId: $message->id,
                        body: $message->body,
                        fromMe: $message->fromMe,
                        occurredAt: $message->occurredAt ?? time(),
                        kind: $message->kind,
                    ));
                }

                if ($pulled !== []) {
                    break;
                }
            }
        } catch (Throwable) {
            // Keep the local ledger when the gateway is unreachable.
        }
    }

    /**
     * @return list<string>
     */
    private function messageChatIds(ProviderAccount $account, string $chatId): array
    {
        if (str_contains($chatId, '@g.us') || str_contains($chatId, '@broadcast')) {
            return [$chatId];
        }

        $peer = InboxPayload::peerKey($chatId);
        $native = match ($account->driver) {
            'waha' => $peer.'@c.us',
            'gowa' => $peer.'@s.whatsapp.net',
            default => $peer,
        };

        return array_values(array_unique([$chatId, $native, $peer]));
    }

    private function reader(ProviderAccount $account): ?ProviderInboxReader
    {
        return match ($account->driver) {
            'waha' => $this->waha,
            'gowa' => $this->gowa,
            default => null,
        };
    }
}
