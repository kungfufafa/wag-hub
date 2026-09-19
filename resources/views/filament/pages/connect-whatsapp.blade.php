@php
    $application = $this->applications()->firstWhere('id', $this->applicationId);
    $presentation = $this->presentation();
@endphp

<div class="space-y-6">
    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900">
        <p class="text-sm text-gray-500">Alur sukses pertama</p>
        <h2 class="mt-1 text-xl font-semibold">Buat aplikasi → Hubungkan WhatsApp → Uji → Salin konfigurasi</h2>
        <ol class="mt-4 flex flex-wrap gap-3 text-sm">
            @foreach (['Aplikasi', 'Jenis koneksi', 'Siapkan', 'Pesan uji', 'Integrasi'] as $index => $label)
                <li @class([
                    'rounded-full px-3 py-1',
                    'bg-emerald-600 text-white' => $this->step === $index + 1,
                    'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300' => $this->step !== $index + 1,
                ])>{{ $index + 1 }}. {{ $label }}</li>
            @endforeach
        </ol>
    </div>

    @if ($this->step === 1)
        <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-white/10 dark:bg-gray-900">
            <h3 class="text-lg font-semibold">Pilih aplikasi</h3>
            <p class="mt-1 text-sm text-gray-500">Aplikasi hanya perlu memahami koneksi WhatsApp, bukan engine atau rute.</p>
            <div class="mt-4 grid gap-3 md:grid-cols-2">
                <label class="text-sm font-medium">Aplikasi
                    <select wire:model="applicationId" class="mt-1 w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-950">
                        @foreach ($this->applications() as $item)
                            <option value="{{ $item->id }}">{{ $item->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm font-medium">Nama koneksi
                    <input wire:model="name" class="mt-1 w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-950" />
                </label>
            </div>
            <div class="mt-4 flex gap-3">
                <x-filament::button tag="a" color="gray" :href="\App\Filament\Resources\ClientApplications\ClientApplicationResource::getUrl('create')">
                    Buat aplikasi baru
                </x-filament::button>
                <x-filament::button wire:click="$set('step', 2)">Lanjut</x-filament::button>
            </div>
        </div>
    @endif

    @if ($this->step === 2)
        <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-white/10 dark:bg-gray-900">
            <h3 class="text-lg font-semibold">Pilih cara menghubungkan</h3>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <button type="button" wire:click="$set('type', 'managed_number')" @class([
                    'rounded-xl border p-4 text-start',
                    'border-emerald-500 bg-emerald-50 dark:bg-emerald-950/40' => $this->type === 'managed_number',
                    'border-gray-200 dark:border-white/10' => $this->type !== 'managed_number',
                ])>
                    <strong>Pakai nomor WhatsApp saya</strong>
                    <p class="mt-1 text-sm text-gray-500">Scan QR atau masukkan pairing code. Routing dibuat otomatis.</p>
                </button>
                <button type="button" wire:click="$set('type', 'provider_route')" @class([
                    'rounded-xl border p-4 text-start',
                    'border-emerald-500 bg-emerald-50 dark:bg-emerald-950/40' => $this->type === 'provider_route',
                    'border-gray-200 dark:border-white/10' => $this->type !== 'provider_route',
                ])>
                    <strong>Pakai provider</strong>
                    <p class="mt-1 text-sm text-gray-500">WAHA, GOWA, Fonnte, WABA, atau WAG Hub. Fallback bisa ditambah nanti.</p>
                </button>
            </div>

            @if ($this->type === 'managed_number')
                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <label class="text-sm font-medium">Mode
                        <select wire:model="mode" class="mt-1 w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-950">
                            <option value="qr">Scan QR</option>
                            <option value="pairing">Kode pairing</option>
                        </select>
                    </label>
                    @if ($this->mode === 'pairing')
                        <label class="text-sm font-medium">Nomor HP
                            <input wire:model="phone" class="mt-1 w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-950" />
                        </label>
                    @endif
                </div>
            @else
                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <label class="text-sm font-medium">Provider
                        <select wire:model.live="driver" class="mt-1 w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-950">
                            <option value="fonnte">Fonnte</option>
                            <option value="waha">WAHA</option>
                            <option value="gowa">GOWA</option>
                            <option value="waba">WABA</option>
                            <option value="wag_hub">WAG Hub</option>
                        </select>
                    </label>
                    @if ($this->driver === 'fonnte')
                        <label class="text-sm font-medium">Token
                            <input type="password" wire:model="token" class="mt-1 w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-950" />
                        </label>
                    @endif
                    @if ($this->driver === 'waha')
                        <label class="text-sm font-medium">Base URL
                            <input wire:model="baseUrl" class="mt-1 w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-950" />
                        </label>
                        <label class="text-sm font-medium">Session
                            <input wire:model="session" class="mt-1 w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-950" />
                        </label>
                        <label class="text-sm font-medium">API key
                            <input type="password" wire:model="token" class="mt-1 w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-950" />
                        </label>
                    @endif
                    @if ($this->driver === 'gowa')
                        <label class="text-sm font-medium">Base URL
                            <input wire:model="baseUrl" class="mt-1 w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-950" />
                        </label>
                        <label class="text-sm font-medium">Username
                            <input wire:model="username" class="mt-1 w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-950" />
                        </label>
                        <label class="text-sm font-medium">Password
                            <input type="password" wire:model="password" class="mt-1 w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-950" />
                        </label>
                    @endif
                    @if ($this->driver === 'waba')
                        <label class="text-sm font-medium">Phone number ID
                            <input wire:model="phoneNumberId" class="mt-1 w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-950" />
                        </label>
                        <label class="text-sm font-medium">Access token
                            <input type="password" wire:model="accessToken" class="mt-1 w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-950" />
                        </label>
                    @endif
                </div>
            @endif

            <div class="mt-4 flex gap-3">
                <x-filament::button color="gray" wire:click="$set('step', 1)">Kembali</x-filament::button>
                <x-filament::button wire:click="createConnection">Siapkan koneksi</x-filament::button>
            </div>
        </div>
    @endif

    @if ($this->step === 3)
        <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-white/10 dark:bg-gray-900" @if (($presentation['status'] ?? null) === 'connecting') wire:poll.3s @endif>
            <h3 class="text-lg font-semibold">Status koneksi</h3>
            <p class="mt-1 text-sm text-gray-500">{{ $presentation['recommended_action'] ?? 'Menunggu kesiapan koneksi.' }}</p>
            <p class="mt-3 text-sm"><strong>Status:</strong> {{ $presentation['status'] ?? 'setup_required' }}</p>
            @if (! empty($presentation['qr']))
                <img src="{{ $presentation['qr'] }}" alt="QR WhatsApp" class="mt-4 h-56 w-56 rounded-lg border" />
            @endif
            @if (! empty($presentation['pairing_code']))
                <p class="mt-4 font-mono text-2xl">{{ $presentation['pairing_code'] }}</p>
            @endif
            <div class="mt-4 flex gap-3">
                <x-filament::button color="gray" wire:click="retrySetup">Coba lagi</x-filament::button>
                <x-filament::button wire:click="$set('step', 4)">Kirim pesan uji</x-filament::button>
            </div>
        </div>
    @endif

    @if ($this->step === 4)
        <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-white/10 dark:bg-gray-900">
            <h3 class="text-lg font-semibold">Kirim pesan uji</h3>
            <div class="mt-4 grid gap-3 md:grid-cols-2">
                <label class="text-sm font-medium">Nomor tujuan
                    <input wire:model="testRecipient" class="mt-1 w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-950" />
                </label>
                <label class="text-sm font-medium">Pesan
                    <input wire:model="testText" class="mt-1 w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-950" />
                </label>
            </div>
            <div class="mt-4 flex gap-3">
                <x-filament::button color="gray" wire:click="$set('step', 3)">Kembali</x-filament::button>
                <x-filament::button wire:click="sendTest">Kirim uji</x-filament::button>
                <x-filament::button color="gray" wire:click="issueIntegration">Lewati ke integrasi</x-filament::button>
            </div>
        </div>
    @endif

    @if ($this->step === 5)
        <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-white/10 dark:bg-gray-900">
            <h3 class="text-lg font-semibold">Salin konfigurasi integrasi</h3>
            <p class="mt-1 text-sm text-gray-500">Aplikasi klien hanya butuh URL, token, dan satu pemanggilan kirim.</p>
            <pre class="mt-4 overflow-x-auto rounded-lg bg-gray-950 p-4 text-xs text-gray-100">{{ $this->issuedEnv }}</pre>
            <pre class="mt-4 overflow-x-auto rounded-lg bg-gray-950 p-4 text-xs text-gray-100">wag.messages.send(null, '081234567890', 'Halo', 'order-1')</pre>
            <div class="mt-4 flex gap-3">
                <x-filament::button x-on:click="{!! $this->envCopyHandler() !!}">Salin WAG_URL dan WAG_TOKEN</x-filament::button>
                <x-filament::button color="gray" tag="a" :href="\App\Filament\Resources\WhatsAppConnections\WhatsAppConnectionResource::getUrl('index')">
                    Selesai
                </x-filament::button>
            </div>
        </div>
    @endif
</div>
