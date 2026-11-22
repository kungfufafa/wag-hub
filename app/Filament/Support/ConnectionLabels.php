<?php

namespace App\Filament\Support;

use App\Domain\Connections\ConnectionStatus;
use App\Exceptions\WhatsAppConnectionException;
use App\Exceptions\WhatsAppEngineException;
use App\Models\WhatsAppConnection;
use InvalidArgumentException;
use Throwable;

final class ConnectionLabels
{
    public static function recommendedAction(?string $action, ?WhatsAppConnection $connection = null): ?string
    {
        if ($connection?->isManaged() && $connection->statusEnum() === ConnectionStatus::Connecting
            && ($connection->setup_state['auth_method'] ?? null) === 'pairing') {
            $action = 'Enter pairing code';
        }

        return match ($action) {
            null, '' => null,
            'Enter pairing code' => 'Masukkan kode pairing di WhatsApp pada HP Anda.',
            'Scan QR' => 'Pindai kode QR melalui menu Perangkat tertaut di WhatsApp.',
            'Fix provider settings', 'Fix credentials' => 'Perbaiki pengaturan provider.',
            'Reconnect' => 'Hubungkan kembali WhatsApp.',
            'Start WAG Hub runner' => 'Jalankan layanan WAG Hub runner.',
            'Provider temporarily unavailable' => 'Provider sementara tidak tersedia.',
            'Send a test message' => 'Kirim pesan uji untuk memeriksa koneksi.',
            default => 'Periksa pengaturan koneksi.',
        };
    }

    public static function status(ConnectionStatus $status): string
    {
        return $status === ConnectionStatus::Error ? 'Kesalahan' : $status->label();
    }

    public static function operationalStatus(string $status): string
    {
        return match ($status) {
            'ready' => 'Siap',
            'degraded' => 'Terganggu',
            'down' => 'Tidak tersedia',
            default => 'Belum diketahui',
        };
    }

    public static function exceptionMessage(Throwable $exception): string
    {
        if ($exception instanceof InvalidArgumentException && in_array($exception->getMessage(), [
            'Nomor WhatsApp tidak valid.',
            'Kode negara WhatsApp tidak didukung.',
            'Panjang nomor WhatsApp tidak valid.',
        ], true)) {
            return $exception->getMessage();
        }

        $code = $exception instanceof WhatsAppConnectionException || $exception instanceof WhatsAppEngineException
            ? $exception->errorCode
            : null;

        return self::errorMessage($code);
    }

    public static function errorMessage(?string $code): string
    {
        return match ($code) {
            'capacity_exceeded' => 'Kuota nomor WhatsApp aplikasi ini sudah penuh. Hapus nomor yang tidak digunakan atau hubungi administrator.',
            'idempotency_conflict' => 'Pengaturan berbeda dari percobaan sebelumnya. Mulai percobaan baru melalui Koneksi lain.',
            'connection_removed', 'session_removed', 'session_not_found' => 'Koneksi atau sesi sudah dihapus. Mulai percobaan baru melalui Koneksi lain.',
            'provider_configuration_invalid' => 'Pengaturan provider belum lengkap atau tidak valid. Pilih Ubah pengaturan untuk memperbaikinya.',
            'provider_endpoint_not_allowed' => 'URL provider tidak diizinkan. Gunakan alamat HTTPS publik yang diizinkan.',
            'invalid_phone', 'recipient_invalid' => 'Nomor WhatsApp tidak valid.',
            'invalid_message' => 'Pesan tidak valid. Isi teks pesan sebelum mengirim.',
            'connection_not_ready', 'session_not_working' => 'Koneksi WhatsApp belum siap. Hubungkan kembali sebelum mengirim pesan.',
            'operation_pending', 'operation_incomplete' => 'Proses koneksi masih berlangsung. Tunggu sebentar lalu coba lagi.',
            'engine_unavailable', 'engine_unconfigured', 'engine_unreachable' => 'Layanan WhatsApp belum tersedia. Periksa WAG Hub runner lalu coba lagi.',
            'capability_not_supported' => 'Operasi ini tidak didukung oleh koneksi. Periksa pengaturan provider atau hubungi administrator.',
            'providers_failed', 'delivery_failed' => 'Tidak ada provider yang menerima pesan. Periksa koneksi lalu coba lagi.',
            'route_unavailable' => 'Tidak ada jalur pengiriman yang tersedia. Periksa pengaturan provider.',
            'provider_outcome_unknown', 'delivery_outcome_unknown' => 'Hasil pengiriman belum dapat dipastikan. Periksa status pesan sebelum mengirim ulang.',
            'message_expired' => 'Pesan kedaluwarsa sebelum dapat dikirim.',
            'rate_limited' => 'Batas pengiriman tercapai. Tunggu sebentar lalu coba lagi.',
            default => 'Periksa pengaturan koneksi dan ketersediaan layanan, lalu coba lagi.',
        };
    }
}
