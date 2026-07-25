<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertDelivery extends Model
{
    use HasUuids;

    protected $fillable = [
        'uuid',
        'provider_account_id',
        'user_id',
        'channel',
        'from_status',
        'to_status',
        'consecutive_failures',
        'circuit_open_until',
        'error_summary',
        'status',
        'failure_reason',
        'sent_at',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'consecutive_failures' => 'integer',
            'circuit_open_until' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
