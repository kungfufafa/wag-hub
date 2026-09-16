<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tracks which menu flow a given chat is currently navigating.
 */
class BotConversationState extends Model
{
    protected $fillable = [
        'provider_account_id',
        'chat_key',
        'bot_flow_id',
        'bot_graph_id',
        'node_id',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function botFlow(): BelongsTo
    {
        return $this->belongsTo(BotFlow::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
