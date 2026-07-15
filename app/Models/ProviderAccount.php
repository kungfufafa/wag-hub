<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProviderAccount extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

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
}
