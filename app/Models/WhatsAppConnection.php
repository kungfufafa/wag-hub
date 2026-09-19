<?php

namespace App\Models;

use App\Domain\Connection\ConnectionStatus;
use App\Domain\Connection\ConnectionType;
use App\Models\Concerns\GeneratesSlugFromName;
use App\Models\Concerns\ReleasesUniqueIdentifierOnSoftDelete;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WhatsAppConnection extends Model
{
    protected $table = 'whatsapp_connections';

    use GeneratesSlugFromName;
    use HasFactory;
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
        'session_id',
        'driver',
        'capabilities',
        'sender_identity',
        'health_summary',
        'next_action',
        'status_detail',
        'provisioning_state',
        'last_successful_send_at',
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
            'sender_identity' => 'array',
            'health_summary' => 'array',
            'provisioning_state' => 'array',
            'last_successful_send_at' => 'datetime',
        ];
    }

    public function connectionType(): ConnectionType
    {
        return ConnectionType::from((string) $this->type);
    }

    public function connectionStatus(): ConnectionStatus
    {
        return ConnectionStatus::from((string) $this->status);
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
        return $this->belongsTo(RoutingPolicy::class);
    }

    public function gatewayMessages(): HasMany
    {
        return $this->hasMany(GatewayMessage::class);
    }

    public function isManagedNumber(): bool
    {
        return $this->connectionType() === ConnectionType::ManagedNumber;
    }

    public function isProviderRoute(): bool
    {
        return $this->connectionType() === ConnectionType::ProviderRoute;
    }
}
