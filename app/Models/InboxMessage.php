<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboxMessage extends Model
{
    protected $fillable = [
        'inbox_conversation_id',
        'provider_message_id',
        'from_me',
        'body',
        'kind',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'from_me' => 'boolean',
            'body' => 'encrypted',
            'occurred_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(InboxConversation::class, 'inbox_conversation_id');
    }
}
