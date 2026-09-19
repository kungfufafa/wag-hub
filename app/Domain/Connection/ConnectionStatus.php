<?php

namespace App\Domain\Connection;

enum ConnectionStatus: string
{
    case SetupRequired = 'setup_required';
    case Connecting = 'connecting';
    case Ready = 'ready';
    case Degraded = 'degraded';
    case Disconnected = 'disconnected';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::SetupRequired => 'Perlu penyiapan',
            self::Connecting => 'Menghubungkan',
            self::Ready => 'Siap',
            self::Degraded => 'Terganggu',
            self::Disconnected => 'Terputus',
            self::Error => 'Error',
        };
    }

    public function isReady(): bool
    {
        return $this === self::Ready;
    }

    public function canSend(): bool
    {
        return in_array($this, [self::Ready, self::Degraded], true);
    }
}
