<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoutingStep extends Model
{
    use HasFactory;

    protected $fillable = [
        'routing_policy_id',
        'provider_account_id',
        'position',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function routingPolicy(): BelongsTo
    {
        return $this->belongsTo(RoutingPolicy::class)->withTrashed();
    }

    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class)->withTrashed();
    }
}
