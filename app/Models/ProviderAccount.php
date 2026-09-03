<?php

namespace App\Models;

use App\Models\Concerns\GeneratesSlugFromName;
use App\Models\Concerns\ReleasesUniqueIdentifierOnSoftDelete;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProviderAccount extends Model
{
    use GeneratesSlugFromName;
    use HasFactory;
    use HasUuids;
    use ReleasesUniqueIdentifierOnSoftDelete;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'name',
        'slug',
        'driver',
        'configuration',
        'is_active',
        'health_status',
        'consecutive_failures',
        'circuit_open_until',
        'timeout_seconds',
    ];

    protected $hidden = [
        'configuration',
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
            'configuration' => 'encrypted:array',
            'is_active' => 'boolean',
            'consecutive_failures' => 'integer',
            'circuit_open_until' => 'datetime',
            'timeout_seconds' => 'integer',
        ];
    }

    public function routingSteps(): HasMany
    {
        return $this->hasMany(RoutingStep::class);
    }

    public function messageAttempts(): HasMany
    {
        return $this->hasMany(MessageAttempt::class);
    }

    public function acceptedMessages(): HasMany
    {
        return $this->hasMany(GatewayMessage::class, 'accepted_provider_account_id');
    }

    public function numberCheckAttempts(): HasMany
    {
        return $this->hasMany(NumberCheckAttempt::class);
    }

    public function resolvedNumberCheckRequests(): HasMany
    {
        return $this->hasMany(NumberCheckRequest::class, 'resolved_provider_account_id');
    }

    public function inboxConversations(): HasMany
    {
        return $this->hasMany(InboxConversation::class);
    }
}
