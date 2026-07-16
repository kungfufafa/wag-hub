<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientApplication extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'uuid',
        'name',
        'slug',
        'is_active',
        'rate_limit_per_minute',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'rate_limit_per_minute' => 'integer',
        ];
    }

    public function apiCredentials(): HasMany
    {
        return $this->hasMany(ApiCredential::class);
    }

    public function routingPolicies(): HasMany
    {
        return $this->hasMany(RoutingPolicy::class);
    }

    public function gatewayMessages(): HasMany
    {
        return $this->hasMany(GatewayMessage::class);
    }

    public function numberCheckRequests(): HasMany
    {
        return $this->hasMany(NumberCheckRequest::class);
    }
}
