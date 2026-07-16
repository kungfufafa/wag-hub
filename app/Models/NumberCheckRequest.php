<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NumberCheckRequest extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'uuid',
        'client_application_id',
        'api_credential_id',
        'routing_policy_id',
        'resolved_provider_account_id',
        'correlation_id',
        'recipient',
        'recipient_hash',
        'recipient_last4',
        'route_key',
        'status',
        'registered',
        'last_error_code',
        'started_at',
        'finished_at',
    ];

    protected $hidden = [
        'recipient',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'recipient' => 'encrypted',
            'registered' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function clientApplication(): BelongsTo
    {
        return $this->belongsTo(ClientApplication::class)->withTrashed();
    }

    public function apiCredential(): BelongsTo
    {
        return $this->belongsTo(ApiCredential::class);
    }

    public function routingPolicy(): BelongsTo
    {
        return $this->belongsTo(RoutingPolicy::class)->withTrashed();
    }

    public function resolvedProviderAccount(): BelongsTo
    {
        return $this->belongsTo(
            ProviderAccount::class,
            'resolved_provider_account_id',
        )->withTrashed();
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(NumberCheckAttempt::class)->orderBy('sequence');
    }
}
