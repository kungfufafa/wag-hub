<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GatewayMessage extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'uuid',
        'client_application_id',
        'routing_policy_id',
        'accepted_provider_account_id',
        'idempotency_key',
        'payload_hash',
        'correlation_id',
        'client_reference',
        'recipient',
        'recipient_hash',
        'recipient_last4',
        'body',
        'purpose',
        'route_key',
        'mode',
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

    public function attempts(): HasMany
    {
        return $this->hasMany(MessageAttempt::class)->orderBy('sequence');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MessageEvent::class)->orderBy('occurred_at');
    }

    public function isSafeToRetry(): bool
    {
        return $this->status === 'failed'
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * Atomically move a safely failed message back to the queue.
     *
     * @throws DomainException
     */
    public function queueForRetry(): void
    {
        if (! $this->exists) {
            throw new DomainException('Only a persisted failed message can be retried.');
        }

        $queuedAt = now();
        $updated = static::query()
            ->whereKey($this->getKey())
            ->where('status', 'failed')
            ->where(function ($query) use ($queuedAt): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $queuedAt);
            })
            ->update([
                'status' => 'queued',
                'queued_at' => $queuedAt,
                'processing_at' => null,
                'failed_at' => null,
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
