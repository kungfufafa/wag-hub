<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlertSetting extends Model
{
    protected $fillable = [
        'is_enabled',
        'cooldown_seconds',
        'smtp_host',
        'smtp_port',
        'smtp_username',
        'smtp_password',
        'smtp_encryption',
        'smtp_from_address',
        'smtp_from_name',
        'telegram_bot_token',
    ];

    protected $hidden = [
        'smtp_password',
        'telegram_bot_token',
    ];

    public static function current(): self
    {
        return static::query()->sole();
    }

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'cooldown_seconds' => 'integer',
            'smtp_port' => 'integer',
            'smtp_password' => 'encrypted',
            'telegram_bot_token' => 'encrypted',
        ];
    }
}
