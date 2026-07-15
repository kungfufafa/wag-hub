<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'gateway_message_id',
        'provider_account_id',
        'sequence',
        'status',
        'delivery_certainty',
        'retry_disposition',
        'http_status',
        'provider_message_id',
        'latency_ms',
        'error_code',
        'error_message',
        'response_excerpt',
        'started_at',
        'finished_at',
    ];

    protected $hidden = [
        'response_excerpt',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'http_status' => 'integer',
            'latency_ms' => 'integer',
            'error_message' => 'encrypted',
            'response_excerpt' => 'encrypted',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function gatewayMessage(): BelongsTo
    {
        return $this->belongsTo(GatewayMessage::class);
    }

    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class)->withTrashed();
    }
}
