<?php

namespace App\Domain\Connection;

enum ConnectionNextAction: string
{
    case ScanQr = 'scan_qr';
    case EnterPairingCode = 'enter_pairing_code';
    case Reconnect = 'reconnect';
    case FixCredentials = 'fix_credentials';
    case ProviderUnavailable = 'provider_unavailable';
    case StartRunner = 'start_runner';
    case RetrySetup = 'retry_setup';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::ScanQr => 'Scan QR WhatsApp',
            self::EnterPairingCode => 'Masukkan kode pairing',
            self::Reconnect => 'Hubungkan ulang',
            self::FixCredentials => 'Perbaiki kredensial provider',
            self::ProviderUnavailable => 'Provider sementara tidak tersedia',
            self::StartRunner => 'Jalankan WAG Hub runner',
            self::RetrySetup => 'Coba penyiapan ulang',
            self::None => 'Tidak ada tindakan',
        };
    }
}
