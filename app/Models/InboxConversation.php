<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InboxConversation extends Model
{
    protected $fillable = [
        'provider_account_id',
        'chat_id',
        'peer_key',
        'title',
        'preview',
        'is_group',
        'last_from_me',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'is_group' => 'boolean',
            'last_from_me' => 'boolean',
            'last_message_at' => 'datetime',
        ];
    }

    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(InboxMessage::class);
    }
}
