<?php

namespace App\Domain\Connections;

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
            self::SetupRequired => 'Perlu disiapkan',
            self::Connecting => 'Menghubungkan',
            self::Ready => 'Siap',
            self::Degraded => 'Terganggu',
            self::Disconnected => 'Terputus',
            self::Error => 'Error',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Ready => 'success',
            self::Connecting, self::Degraded => 'warning',
            self::Error, self::Disconnected => 'danger',
            self::SetupRequired => 'gray',
        };
    }

    public function operational(): string
    {
        return match ($this) {
            self::Ready => 'ready',
            self::Degraded, self::Connecting => 'degraded',
            default => 'down',
        };
    }

    public function isReady(): bool
    {
        return $this === self::Ready;
    }

    public function canAttemptSend(): bool
    {
        return in_array($this, [self::Ready, self::Degraded], true);
    }
}
