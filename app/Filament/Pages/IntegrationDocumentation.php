<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Forms\Components\Placeholder;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * @property-read Schema $content
 */
class IntegrationDocumentation extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $navigationLabel = 'Dokumentasi Integrasi';

    protected static ?string $title = 'Dokumentasi Integrasi';

    protected static ?string $slug = 'dokumentasi-integrasi';

    protected static bool $shouldRegisterNavigation = false;

    protected Width|string|null $maxContentWidth = Width::Full;

    public function content(Schema $schema): Schema
    {
        return $schema
            ->columns([
                'default' => 1,
                'lg' => 3,
            ])
            ->components([
                Section::make('Siapkan aplikasi sumber')
                    ->description('Urutan setup di panel ini sebelum aplikasi lain memanggil API Hub.')
                    ->icon(Heroicon::OutlinedQueueList)
                    ->schema([
                        Placeholder::make('step_1')
                            ->label('1. Buat Aplikasi Klien')
                            ->content('Satu sistem sumber = satu aplikasi. Beri nama yang jelas (misalnya Web Shelf).'),
                        Placeholder::make('step_2')
                            ->label('2. Terbitkan kredensial API')
                            ->content('Salin Bearer token saat ditampilkan. Plaintext tidak bisa dilihat lagi setelah itu.'),
                        Placeholder::make('step_3')
                            ->label('3. Siapkan provider & aturan rute')
                            ->content('Pastikan ada Akun Provider aktif dan Aturan Rute untuk route_key + purpose yang akan dipakai aplikasi.'),
                        Placeholder::make('step_4')
                            ->label('4. Panggil API dari aplikasi sumber')
                            ->content('Kirim request dengan Authorization + Idempotency-Key. Hub yang memilih provider dan fallback.'),
                    ])
                    ->columnSpan([
                        'default' => 'full',
                        'lg' => 2,
                    ]),

                Section::make('Endpoint cepat')
                    ->description('Base URL diganti sesuai host Hub Anda.')
                    ->icon(Heroicon::OutlinedGlobeAlt)
                    ->schema([
                        Placeholder::make('endpoint_send')
                            ->label('Kirim pesan')
                            ->content(new HtmlString('<code class="text-xs">POST /api/v1/messages</code>')),
                        Placeholder::make('endpoint_attachment')
                            ->label('Upload attachment')
                            ->content(new HtmlString('<code class="text-xs">POST /api/v1/attachments</code><div class="mt-1 text-xs text-gray-500 dark:text-gray-400">multipart file, maksimum 16 MB</div>')),
                        Placeholder::make('endpoint_status')
                            ->label('Status pesan')
                            ->content(new HtmlString('<code class="text-xs">GET /api/v1/messages/{uuid}</code>')),
                        Placeholder::make('endpoint_check')
                            ->label('Cek nomor')
                            ->content(new HtmlString('<code class="text-xs">POST /api/v1/number-checks</code><div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Butuh ability numbers:check</div>')),
                        Placeholder::make('endpoint_engine')
                            ->label('Engine cesa-web')
                            ->content(new HtmlString('<code class="text-xs">/engine</code> atau <code class="text-xs">/engine/t/{token}</code><div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Kontrak health/session/send yang sama dengan engine Rekrutmen CESA</div>')),
                    ])
                    ->columnSpan([
                        'default' => 'full',
                        'lg' => 1,
                    ]),

                Section::make('Header wajib')
                    ->description('Setiap request pesan memakai header berikut.')
                    ->icon(Heroicon::OutlinedKey)
                    ->schema([
                        Placeholder::make('headers')
                            ->hiddenLabel()
                            ->content(new HtmlString(<<<'HTML'
                                <ul class="list-disc space-y-1.5 ps-5 text-sm">
                                    <li><code>Authorization: Bearer &lt;token&gt;</code></li>
                                    <li><code>Idempotency-Key: &lt;kunci-unik-bisnis&gt;</code> — stabil per event, jangan UUID acak tiap retry</li>
                                    <li><code>Accept: application/json</code></li>
                                    <li><code>Content-Type: application/json</code></li>
                                </ul>
                            HTML)),
                    ])
                    ->columnSpan([
                        'default' => 'full',
                        'lg' => 2,
                    ]),

                Section::make('Checklist')
                    ->description('Sebelum go-live aplikasi baru.')
                    ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                    ->schema([
                        Placeholder::make('checklist')
                            ->hiddenLabel()
                            ->content(new HtmlString(<<<'HTML'
                                <ul class="list-disc space-y-1.5 ps-5 text-sm">
                                    <li>Token hanya di secret store — jangan di-commit</li>
                                    <li><code>route_key</code> &amp; <code>purpose</code> cocok dengan Aturan Rute</li>
                                    <li>Pantau ledger Pesan bila pengiriman gagal</li>
                                    <li>Health provider &amp; alert Telegram/email sudah aktif</li>
                                    <li>Produksi: <code>GATEWAY_DISPATCH=async</code> + <code>queue:work</code>. <code>sync</code> hanya untuk lokal/dev (intake async menunggu sampai selesai di request HTTP)</li>
                                </ul>
                            HTML)),
                    ])
                    ->columnSpan([
                        'default' => 'full',
                        'lg' => 1,
                    ]),

                Section::make('Contoh: kirim OTP (sync)')
                    ->description('Mode sync menunggu provider menerima. Untuk notifikasi, ganti mode menjadi async dan purpose menjadi notification.')
                    ->icon(Heroicon::OutlinedCodeBracket)
                    ->schema([
                        Placeholder::make('curl_send')
                            ->hiddenLabel()
                            ->content(new HtmlString(
                                '<pre class="overflow-x-auto rounded-lg bg-gray-950 p-4 text-xs leading-5 text-gray-100 dark:bg-black/40">'.
                                e(<<<'BASH'
curl -X POST https://gateway.example.com/api/v1/messages \
  -H 'Accept: application/json' \
  -H 'Authorization: Bearer wgh_TOKEN_ANDA' \
  -H 'Idempotency-Key: otp-login-0001' \
  -H 'Content-Type: application/json' \
  -d '{
    "recipient": {"type": "phone", "value": "081234567890"},
    "message": {"type": "text", "text": "Kode OTP Anda: 123456"},
    "purpose": "otp",
    "mode": "sync",
    "route_key": "default",
    "expires_at": "2030-01-01T12:05:00+07:00",
    "client_reference": "otp-0001"
  }'
BASH).
                                '</pre>'
                            )),
                    ])
                    ->columnSpanFull(),

                Section::make('Contoh: kirim lampiran (async)')
                    ->description('Upload file ke POST /api/v1/attachments lalu gunakan data.id, atau gunakan attachment.url publik. Satu pesan memiliki satu lampiran; caption maksimal 1.024 karakter dan audio tanpa caption.')
                    ->icon(Heroicon::OutlinedPaperClip)
                    ->schema([
                        Placeholder::make('curl_send_attachment')
                            ->hiddenLabel()
                            ->content(new HtmlString(
                                '<pre class="overflow-x-auto rounded-lg bg-gray-950 p-4 text-xs leading-5 text-gray-100 dark:bg-black/40">'.
                                e(<<<'BASH'
curl -X POST https://gateway.example.com/api/v1/messages \
  -H 'Accept: application/json' \
  -H 'Authorization: Bearer wgh_TOKEN_ANDA' \
  -H 'Idempotency-Key: invoice-0001' \
  -H 'Content-Type: application/json' \
  -d '{
    "recipient": {"type": "phone", "value": "081234567890"},
    "message": {
      "type": "document",
      "text": "Terlampir invoice Anda.",
      "attachment": {
        "url": "https://cdn.example.com/invoices/INV-0001.pdf",
        "filename": "INV-0001.pdf",
        "mime_type": "application/pdf"
      }
    },
    "purpose": "transactional",
    "mode": "async",
    "route_key": "default",
    "client_reference": "invoice-0001"
  }'
BASH).
                                '</pre>'
                            )),
                    ])
                    ->columnSpanFull(),

                Section::make('Contoh: cek nomor WhatsApp')
                    ->description('Pakai Aturan Rute berjenis Cek nomor WhatsApp (terpisah dari rute kirim pesan).')
                    ->icon(Heroicon::OutlinedDevicePhoneMobile)
                    ->schema([
                        Placeholder::make('curl_check')
                            ->hiddenLabel()
                            ->content(new HtmlString(
                                '<pre class="overflow-x-auto rounded-lg bg-gray-950 p-4 text-xs leading-5 text-gray-100 dark:bg-black/40">'.
                                e(<<<'BASH'
curl -X POST https://gateway.example.com/api/v1/number-checks \
  -H 'Accept: application/json' \
  -H 'Authorization: Bearer wgh_TOKEN_ANDA' \
  -H 'Content-Type: application/json' \
  -d '{
    "recipient": {"type": "phone", "value": "081234567890"},
    "route_key": "default"
  }'
BASH).
                                '</pre>'
                            )),
                    ])
                    ->columnSpanFull(),

                Section::make('Arti status pesan')
                    ->description('Status di ledger dan response API.')
                    ->icon(Heroicon::OutlinedInformationCircle)
                    ->schema([
                        Placeholder::make('statuses')
                            ->hiddenLabel()
                            ->content(new HtmlString(<<<'HTML'
                                <div class="overflow-x-auto">
                                    <table class="w-full table-auto divide-y divide-gray-200 text-start text-sm dark:divide-white/10">
                                        <thead>
                                            <tr class="bg-gray-50 dark:bg-white/5">
                                                <th class="px-3 py-2 font-medium">Status</th>
                                                <th class="px-3 py-2 font-medium">Makna untuk operator</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                            <tr><td class="px-3 py-2 font-medium">queued</td><td class="px-3 py-2">Tersimpan; menunggu worker bila <code>GATEWAY_DISPATCH=async</code>, atau segera diproses inline bila <code>sync</code></td></tr>
                                            <tr><td class="px-3 py-2 font-medium">processing</td><td class="px-3 py-2">Sedang diproses routing</td></tr>
                                            <tr><td class="px-3 py-2 font-medium">provider_accepted</td><td class="px-3 py-2">Provider menerima request — bukan klaim delivered/read</td></tr>
                                            <tr><td class="px-3 py-2 font-medium">failed</td><td class="px-3 py-2">Gagal definitif; boleh ditinjau untuk retry manual</td></tr>
                                            <tr><td class="px-3 py-2 font-medium">outcome_unknown</td><td class="px-3 py-2">Hasil tidak pasti; jangan kirim ulang otomatis</td></tr>
                                            <tr><td class="px-3 py-2 font-medium">expired</td><td class="px-3 py-2">Melewati batas waktu sebelum diterima provider</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            HTML)),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
