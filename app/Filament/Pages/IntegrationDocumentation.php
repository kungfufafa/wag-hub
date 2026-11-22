<?php

namespace App\Filament\Pages;

use App\Filament\Support\PanelNavigation;
use BackedEnum;
use Filament\Forms\Components\Placeholder;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * @property-read Schema $content
 */
class IntegrationDocumentation extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $navigationLabel = 'Dokumentasi Integrasi';

    protected static ?string $title = 'Dokumentasi Integrasi';

    protected static ?string $slug = 'dokumentasi-integrasi';

    protected static string|UnitEnum|null $navigationGroup = PanelNavigation::SERVER;

    protected static ?int $navigationSort = 30;

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
                Section::make('Setup')
                    ->icon(Heroicon::OutlinedQueueList)
                    ->schema([
                        Placeholder::make('step_1')
                            ->label('1. Aplikasi Klien')
                            ->content('Satu aplikasi per repo.'),
                        Placeholder::make('step_2')
                            ->label('2. Hubungkan WhatsApp')
                            ->content('QR/pairing atau provider. Buat WAG_TOKEN.'),
                        Placeholder::make('step_3')
                            ->label('3. Kirim pesan uji')
                            ->content('POST /api/v2/connections/{id}/messages'),
                        Placeholder::make('step_4')
                            ->label('4. Integrasi')
                            ->content('Salin WAG_URL dan WAG_TOKEN.'),
                    ])
                    ->columnSpan([
                        'default' => 'full',
                        'lg' => 2,
                    ]),

                Section::make('Endpoint cepat')
                    ->icon(Heroicon::OutlinedGlobeAlt)
                    ->schema([
                        Placeholder::make('endpoint_connections')
                            ->label('Koneksi WhatsApp (v2)')
                            ->content(new HtmlString('<code class="text-xs">GET/POST /api/v2/connections</code>')),
                        Placeholder::make('endpoint_connection_send')
                            ->label('Kirim lewat koneksi')
                            ->content(new HtmlString('<code class="text-xs">POST /api/v2/connections/{id}/messages</code><div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Idempotency-Key wajib. 202 nomor terkelola, 201 provider.</div>')),
                        Placeholder::make('endpoint_send')
                            ->label('Kirim pesan (legacy v1)')
                            ->content(new HtmlString('<code class="text-xs">POST /api/v1/messages</code>')),
                        Placeholder::make('endpoint_attachment')
                            ->label('Upload attachment')
                            ->content(new HtmlString('<code class="text-xs">POST /api/v1/attachments</code><div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Maks. 16 MB</div>')),
                        Placeholder::make('endpoint_status')
                            ->label('Status pesan')
                            ->content(new HtmlString('<code class="text-xs">GET /api/v1/messages/{uuid}</code>')),
                        Placeholder::make('endpoint_check')
                            ->label('Cek nomor')
                            ->content(new HtmlString('<code class="text-xs">POST /api/v1/number-checks</code><div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Ability numbers:check</div>')),
                        Placeholder::make('endpoint_engine')
                            ->label('/engine')
                            ->content(new HtmlString('<code class="text-xs">GET/POST /engine/t/{token}/sessions</code><div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Ability engine:use</div>')),
                    ])
                    ->columnSpan([
                        'default' => 'full',
                        'lg' => 1,
                    ]),

                Section::make('Header wajib')
                    ->icon(Heroicon::OutlinedKey)
                    ->schema([
                        Placeholder::make('headers')
                            ->hiddenLabel()
                            ->content(new HtmlString(<<<'HTML'
                                <ul class="list-disc space-y-1.5 ps-5 text-sm">
                                    <li><code>Authorization: Bearer &lt;token&gt;</code></li>
                                    <li><code>Idempotency-Key: &lt;kunci-unik-bisnis&gt;</code></li>
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
                    ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                    ->schema([
                        Placeholder::make('checklist')
                            ->hiddenLabel()
                            ->content(new HtmlString(<<<'HTML'
                                <ul class="list-disc space-y-1.5 ps-5 text-sm">
                                    <li>Token di secret store</li>
                                    <li><code>route_key</code> &amp; <code>purpose</code> sesuai Aturan Rute</li>
                                    <li>Alert Telegram/email aktif</li>
                                    <li>Produksi: <code>GATEWAY_DISPATCH=async</code> + <code>queue:work</code></li>
                                </ul>
                            HTML)),
                    ])
                    ->columnSpan([
                        'default' => 'full',
                        'lg' => 1,
                    ]),

                Section::make('Contoh: kirim OTP (sync)')
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
                    ->description('Satu lampiran per pesan, caption maks. 1.024 karakter.')
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
                                                <th class="px-3 py-2 font-medium">Arti</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                            <tr><td class="px-3 py-2 font-medium">queued</td><td class="px-3 py-2">Menunggu worker</td></tr>
                                            <tr><td class="px-3 py-2 font-medium">processing</td><td class="px-3 py-2">Sedang diproses</td></tr>
                                            <tr><td class="px-3 py-2 font-medium">provider_accepted</td><td class="px-3 py-2">Diterima provider (bukan delivered/read)</td></tr>
                                            <tr><td class="px-3 py-2 font-medium">failed</td><td class="px-3 py-2">Gagal</td></tr>
                                            <tr><td class="px-3 py-2 font-medium">outcome_unknown</td><td class="px-3 py-2">Hasil tidak pasti; jangan kirim ulang</td></tr>
                                            <tr><td class="px-3 py-2 font-medium">expired</td><td class="px-3 py-2">Kedaluwarsa</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            HTML)),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
