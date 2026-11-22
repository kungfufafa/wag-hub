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
                            ->content('Dari halaman koneksi.'),
                        Placeholder::make('step_4')
                            ->label('4. Integrasi')
                            ->content('Isi WAG_URL dan WAG_TOKEN di .env aplikasi.'),
                    ])
                    ->columnSpan([
                        'default' => 'full',
                        'lg' => 2,
                    ]),

                Section::make('Endpoint cepat')
                    ->icon(Heroicon::OutlinedGlobeAlt)
                    ->schema([
                        Placeholder::make('endpoint_send')
                            ->label('Kirim pesan')
                            ->content(new HtmlString('<code class="text-xs">POST /api/v2/messages</code><div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Pakai koneksi default aplikasi.</div>')),
                        Placeholder::make('endpoint_status')
                            ->label('Status pesan')
                            ->content(new HtmlString('<code class="text-xs">GET /api/v2/messages/{id}</code>')),
                        Placeholder::make('endpoint_check')
                            ->label('Cek nomor')
                            ->content(new HtmlString('<code class="text-xs">POST /api/v1/number-checks</code>')),
                        Placeholder::make('endpoint_connections')
                            ->label('Daftar koneksi')
                            ->content(new HtmlString('<code class="text-xs">GET /api/v2/connections</code><div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Hanya jika punya lebih dari satu nomor.</div>')),
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
                                    <li><code>Idempotency-Key: &lt;kunci-unik&gt;</code> (wajib untuk kirim pesan)</li>
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
                                    <li><code>WAG_URL</code> &amp; <code>WAG_TOKEN</code> di secret store</li>
                                    <li>Alert Telegram/email aktif</li>
                                    <li>Produksi: <code>GATEWAY_DISPATCH=async</code> + <code>queue:work</code></li>
                                </ul>
                            HTML)),
                    ])
                    ->columnSpan([
                        'default' => 'full',
                        'lg' => 1,
                    ]),

                Section::make('Contoh: kirim pesan')
                    ->icon(Heroicon::OutlinedCodeBracket)
                    ->schema([
                        Placeholder::make('curl_send')
                            ->hiddenLabel()
                            ->content(new HtmlString(
                                '<pre class="overflow-x-auto rounded-lg bg-gray-950 p-4 text-xs leading-5 text-gray-100 dark:bg-black/40">'.
                                e(<<<'BASH'
curl -X POST "$WAG_URL/api/v2/messages" \
  -H "Authorization: Bearer $WAG_TOKEN" \
  -H 'Idempotency-Key: order-1001' \
  -H 'Content-Type: application/json' \
  -d '{"to": "081234567890", "text": "Pesanan Anda sudah dikirim."}'
BASH).
                                '</pre>'
                            )),
                    ])
                    ->columnSpanFull(),

                Section::make('Contoh: kirim file')
                    ->icon(Heroicon::OutlinedPaperClip)
                    ->schema([
                        Placeholder::make('curl_send_attachment')
                            ->hiddenLabel()
                            ->content(new HtmlString(
                                '<pre class="overflow-x-auto rounded-lg bg-gray-950 p-4 text-xs leading-5 text-gray-100 dark:bg-black/40">'.
                                e(<<<'BASH'
curl -X POST "$WAG_URL/api/v2/messages" \
  -H "Authorization: Bearer $WAG_TOKEN" \
  -H 'Idempotency-Key: invoice-1001' \
  -H 'Content-Type: application/json' \
  -d '{"to": "081234567890", "document": "https://cdn.example.com/INV-1001.pdf", "text": "Invoice Anda"}'
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
curl -X POST "$WAG_URL/api/v1/number-checks" \
  -H "Authorization: Bearer $WAG_TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"recipient": {"type": "phone", "value": "081234567890"}}'
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
                                            <tr><td class="px-3 py-2 font-medium">queued</td><td class="px-3 py-2">Menunggu dikirim</td></tr>
                                            <tr><td class="px-3 py-2 font-medium">sent</td><td class="px-3 py-2">Terkirim</td></tr>
                                            <tr><td class="px-3 py-2 font-medium">delivered</td><td class="px-3 py-2">Sampai di perangkat</td></tr>
                                            <tr><td class="px-3 py-2 font-medium">read</td><td class="px-3 py-2">Dibaca</td></tr>
                                            <tr><td class="px-3 py-2 font-medium">failed</td><td class="px-3 py-2">Gagal</td></tr>
                                            <tr><td class="px-3 py-2 font-medium">expired</td><td class="px-3 py-2">Kedaluwarsa</td></tr>
                                            <tr><td class="px-3 py-2 font-medium">unknown</td><td class="px-3 py-2">Hasil tidak pasti; kirim ulang dengan Idempotency-Key yang sama</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            HTML)),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
