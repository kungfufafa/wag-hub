<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NumberCheckAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'number_check_request_id',
        'provider_account_id',
        'sequence',
        'status',
        'registered',
        'http_status',
        'reason_code',
        'latency_ms',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'registered' => 'boolean',
            'http_status' => 'integer',
            'latency_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function numberCheckRequest(): BelongsTo
    {
        return $this->belongsTo(NumberCheckRequest::class);
    }

    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class)->withTrashed();
    }
}
