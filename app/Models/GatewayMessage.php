<?php

namespace App\Models;

use App\Domain\Delivery\OutboundAttachment;
use App\Domain\Delivery\OutboundMessage;
use App\Services\AttachmentService;
use DomainException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class GatewayMessage extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'uuid',
        'client_application_id',
        'whatsapp_connection_id',
        'routing_policy_id',
        'accepted_provider_account_id',
        'idempotency_key',
        'inbox_submission_uuid',
        'payload_hash',
        'correlation_id',
        'client_reference',
        'recipient',
        'recipient_hash',
        'recipient_last4',
        'body',
        'message_type',
        'attachment',
        'purpose',
        'route_key',
        'mode',
        'origin',
        'origin_user_id',
        'pinned_provider_account_id',
        'inbox_chat_id',
        'priority',
        'status',
        'metadata',
        'provider_message_id',
        'last_error_code',
        'last_error_message',
        'expires_at',
        'queued_at',
        'processing_at',
        'provider_accepted_at',
        'failed_at',
        'outcome_unknown_at',
        'dead_lettered_at',
    ];

    protected $hidden = [
        'recipient',
        'body',
        'attachment',
        'metadata',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'recipient' => 'encrypted',
            'body' => 'encrypted',
            'attachment' => 'encrypted:array',
            'metadata' => 'encrypted:array',
            'last_error_message' => 'encrypted',
            'priority' => 'integer',
            'expires_at' => 'datetime',
            'queued_at' => 'datetime',
            'processing_at' => 'datetime',
            'provider_accepted_at' => 'datetime',
            'failed_at' => 'datetime',
            'outcome_unknown_at' => 'datetime',
            'dead_lettered_at' => 'datetime',
        ];
    }

    public function clientApplication(): BelongsTo
    {
        return $this->belongsTo(ClientApplication::class)->withTrashed();
    }

    public function routingPolicy(): BelongsTo
    {
        return $this->belongsTo(RoutingPolicy::class)->withTrashed();
    }

    public function acceptedProviderAccount(): BelongsTo
    {
        return $this->belongsTo(
            ProviderAccount::class,
            'accepted_provider_account_id',
        )->withTrashed();
    }

    public function pinnedProviderAccount(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class, 'pinned_provider_account_id')->withTrashed();
    }

    public function whatsappConnection(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConnection::class)->withTrashed();
    }

    public function originUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'origin_user_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(MessageAttempt::class)->orderBy('sequence');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MessageEvent::class)->orderBy('occurred_at');
    }

    public function plaintextBody(): string
    {
        $this->makeVisible(['body']);

        if (filled($this->getAttribute('body'))) {
            return (string) $this->getAttribute('body');
        }

        $fresh = static::query()->find($this->getKey());

        return (string) ($fresh?->makeVisible(['body'])->getAttribute('body') ?? '');
    }

    public function outboundAttachment(): ?OutboundAttachment
    {
        $attachment = $this->getAttribute('attachment');

        return OutboundAttachment::fromArray(is_array($attachment) ? $attachment : null);
    }

    public function toOutboundMessage(): OutboundMessage
    {
        $attachment = $this->outboundAttachment();
        $type = (string) ($this->message_type ?: 'text');

        if ($type !== 'text' && $attachment === null) {
            throw new InvalidArgumentException('Attachment pesan tidak valid atau sudah tidak tersedia.');
        }

        if ($type === 'text' && $attachment !== null) {
            throw new InvalidArgumentException('Pesan teks tidak boleh memiliki attachment.');
        }

        if ($attachment !== null && $attachment->kind->value !== $type) {
            throw new InvalidArgumentException('Jenis attachment pesan tidak sesuai.');
        }

        if ($attachment !== null && $attachment->attachmentId !== null) {
            $attachment = app(AttachmentService::class)->resolve($attachment);
        }

        return new OutboundMessage(
            recipient: (string) $this->recipient,
            body: (string) $this->body,
            attachment: $attachment,
            chatId: $this->inbox_chat_id,
            idempotencyKey: (string) $this->uuid,
        );
    }

    public function isSafeToRetry(): bool
    {
        if (! in_array($this->status, ['failed', 'outcome_unknown'], true)
            || ($this->expires_at !== null && $this->expires_at->isPast())) {
            return false;
        }

        $attachmentId = $this->outboundAttachment()?->attachmentId;

        if ($attachmentId === null) {
            return true;
        }

        return Attachment::query()
            ->where('uuid', $attachmentId)
            ->first()?->isAvailable() === true;
    }

    /**
     * Atomically move a failed or uncertain message back to the queue.
     *
     * @throws DomainException
     */
    public function queueForRetry(): void
    {
        if (! $this->exists) {
            throw new DomainException('Only a persisted failed or uncertain message can be retried.');
        }

        if (! $this->isSafeToRetry()) {
            throw new DomainException('Pesan tidak dapat dikirim ulang karena status, batas waktu, atau file attachment.');
        }

        $queuedAt = now();
        $updated = static::query()
            ->whereKey($this->getKey())
            ->whereIn('status', ['failed', 'outcome_unknown'])
            ->where(function ($query) use ($queuedAt): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $queuedAt);
            })
            ->update([
                'status' => 'queued',
                'queued_at' => $queuedAt,
                'processing_at' => null,
                'failed_at' => null,
                'outcome_unknown_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
                'updated_at' => $queuedAt,
            ]);

        if ($updated !== 1) {
            throw new DomainException('This message is not in a safe retry state.');
        }

        $this->refresh();
    }
}
