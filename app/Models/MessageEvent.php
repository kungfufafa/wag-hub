<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'gateway_message_id',
        'type',
        'source',
        'data',
        'occurred_at',
    ];

    protected $hidden = [
        'data',
    ];

    protected static function booted(): void
    {
        static::creating(function (MessageEvent $event): void {
            $event->occurred_at ??= now();
        });
    }

    protected function casts(): array
    {
        return [
            'data' => 'encrypted:array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function gatewayMessage(): BelongsTo
    {
        return $this->belongsTo(GatewayMessage::class);
    }
}
