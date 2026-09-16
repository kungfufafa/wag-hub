<?php

namespace App\Domain\WhatsApp;

/**
 * Live pairing state of a self-hosted WhatsApp engine session (WAHA/Baileys NOWEB).
 */
enum SessionStatus: string
{
    case Unknown = 'unknown';
    case Stopped = 'stopped';
    case Starting = 'starting';
    case ScanQr = 'scan_qr';
    case Working = 'working';
    case Failed = 'failed';

    /**
     * Map a raw WAHA session status onto our internal state.
     */
    public static function fromWaha(?string $status): self
    {
        return match (strtoupper(trim((string) $status))) {
            'STOPPED' => self::Stopped,
            'STARTING' => self::Starting,
            'SCAN_QR_CODE' => self::ScanQr,
            'WORKING' => self::Working,
            'FAILED' => self::Failed,
            default => self::Unknown,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Belum diketahui',
            self::Stopped => 'Berhenti',
            self::Starting => 'Menghubungkan',
            self::ScanQr => 'Menunggu scan QR',
            self::Working => 'Terhubung',
            self::Failed => 'Gagal',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Working => 'success',
            self::ScanQr, self::Starting => 'warning',
            self::Failed => 'danger',
            default => 'gray',
        };
    }

    public function isConnected(): bool
    {
        return $this === self::Working;
    }

    public function needsQr(): bool
    {
        return $this === self::ScanQr;
    }
}
