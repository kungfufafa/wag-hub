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

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function uniqueIdentifierColumn(): string
    {
        return 'slug';
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

    public function isConfigured(): bool
    {
        return $this->provider_account_id !== null && $this->routing_policy_id !== null;
    }

    public function canAttemptSend(): bool
    {
        if ($this->statusEnum()->canAttemptSend()) {
            return true;
        }

        return $this->statusEnum() === ConnectionStatus::SetupRequired && $this->isConfigured();
    }

    public function clientApplication(): BelongsTo
    {
        return $this->belongsTo(ClientApplication::class);
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

    public function sessionId(): string
    {
        $stored = $this->setup_state['session_id'] ?? null;

        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        return 'wa-'.substr(str_replace('-', '', (string) $this->uuid), 0, 8);
    }
}
