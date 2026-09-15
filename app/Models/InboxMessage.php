<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboxMessage extends Model
{
    protected $fillable = [
        'inbox_conversation_id',
        'gateway_message_id',
        'provider_message_id',
        'from_me',
        'body',
        'kind',
        'attachment',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'from_me' => 'boolean',
            'body' => 'encrypted',
            'attachment' => 'encrypted:array',
            'occurred_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(InboxConversation::class, 'inbox_conversation_id');
    }

    public function gatewayMessage(): BelongsTo
    {
        return $this->belongsTo(GatewayMessage::class);
    }
}
