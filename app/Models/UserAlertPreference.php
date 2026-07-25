<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAlertPreference extends Model
{
    protected $fillable = [
        'user_id',
        'telegram_enabled',
        'telegram_chat_id',
        'email_enabled',
        'email_address',
    ];

    protected function casts(): array
    {
        return [
            'telegram_enabled' => 'boolean',
            'email_enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
