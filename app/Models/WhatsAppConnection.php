<?php

namespace App\Models;

use App\Domain\Connections\ConnectionStatus;
use App\Domain\Connections\ConnectionType;
use App\Models\Concerns\GeneratesSlugFromName;
use App\Models\Concerns\ReleasesUniqueIdentifierOnSoftDelete;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WhatsAppConnection extends Model
{
    protected $table = 'whatsapp_connections';

    use GeneratesSlugFromName;
    use HasUuids;
    use ReleasesUniqueIdentifierOnSoftDelete;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'client_application_id',
        'name',
        'slug',
        'type',
        'engine_session_id',
        'status',
        'is_default',
        'provider_account_id',
        'routing_policy_id',
        'number_check_policy_id',
        'capabilities',
        'setup_state',
        'last_error_code',
        'last_error_message',
        'recommended_action',
    ];

    protected $hidden = ['creation_key', 'creation_hash'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function uniqueIdentifierColumn(): string
    {
        return 'slug';
    }

    protected function deactivatedOnSoftDeleteColumn(): ?string
    {
        return null;
    }

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'capabilities' => 'array',
            'setup_state' => 'array',
        ];
    }

    public function typeEnum(): ConnectionType
    {
        return ConnectionType::from((string) $this->type);
    }

    public function statusEnum(): ConnectionStatus
    {
        return ConnectionStatus::from((string) ($this->status ?: ConnectionStatus::SetupRequired->value));
    }

    public function pinsSender(): bool
    {
        return $this->typeEnum()->pinsSender();
    }

    public function isManaged(): bool
    {
        return $this->typeEnum() === ConnectionType::ManagedNumber;
    }

    public function isConfigured(): bool
    {
        if ($this->isManaged()) {
            return $this->engine_session_id !== null;
        }

        return $this->provider_account_id !== null && $this->routing_policy_id !== null;
    }

    public function canAttemptSend(): bool
    {
        // A managed number only sends from a WORKING, freshly observed engine session.
        if ($this->isManaged()) {
            return $this->statusEnum() === ConnectionStatus::Ready;
        }

        if ($this->statusEnum()->canAttemptSend()) {
            return true;
        }

        return $this->statusEnum() === ConnectionStatus::SetupRequired && $this->isConfigured();
    }

    public function clientApplication(): BelongsTo
    {
        return $this->belongsTo(ClientApplication::class);
    }

    /**
     * The v2 engine session behind a managed number, removed sessions included.
     */
    public function engineSession(): BelongsTo
    {
        return $this->belongsTo(EngineSession::class)->withTrashed();
    }

    /**
     * The linked session only when it belongs to this connection's application, so a
     * mismatched row can never surface or command another tenant's session.
     */
    public function ownedEngineSession(): ?EngineSession
    {
        $session = $this->engineSession;

        if ($session === null || (int) $session->client_application_id !== (int) $this->client_application_id) {
            return null;
        }

        return $session;
    }

    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class);
    }

    public function routingPolicy(): BelongsTo
    {
        return $this->belongsTo(RoutingPolicy::class, 'routing_policy_id');
    }

    public function numberCheckPolicy(): BelongsTo
    {
        return $this->belongsTo(RoutingPolicy::class, 'number_check_policy_id');
    }

    public function gatewayMessages(): HasMany
    {
        return $this->hasMany(GatewayMessage::class);
    }

    /**
     * Slug of the provider account this connection creates for tenant-supplied settings.
     */
    public function providerAccountSlug(): string
    {
        return ProviderAccount::CONNECTION_SLUG_PREFIX.hash('sha256', (string) $this->uuid);
    }

    /**
     * A connection may only change, pair or read pairing secrets of the provider
     * account it created itself, or a native session owned by its own application.
     */
    public function ownsProviderAccount(ProviderAccount $account): bool
    {
        if ($account->slug === $this->providerAccountSlug()) {
            return true;
        }

        $owner = $account->configuration['owned_by_application_id'] ?? null;

        return $owner !== null && (string) $owner === (string) $this->client_application_id;
    }
}
