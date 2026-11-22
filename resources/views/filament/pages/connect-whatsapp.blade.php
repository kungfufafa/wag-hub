@php
    $application = $this->applications()->firstWhere('id', $this->applicationId);
    $presentation = $this->presentation();
@endphp

<div class="space-y-6">
    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900">
        <h2 class="text-xl font-semibold">Buat aplikasi → Hubungkan WhatsApp → Uji → Salin konfigurasi</h2>
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
            <div class="mt-4 grid gap-3 md:grid-cols-2">
                <label class="text-sm font-medium">Aplikasi
                    <x-filament::input.wrapper class="mt-1">
                        <x-filament::input.select wire:model="applicationId">
                        @foreach ($this->applications() as $item)
                            <option value="{{ $item->id }}">{{ $item->name }}</option>
                        @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
                <label class="text-sm font-medium">Nama koneksi
                    <x-filament::input.wrapper class="mt-1">
                        <x-filament::input wire:model="name" />
                    </x-filament::input.wrapper>
                        @error('name') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                </label>
            </div>
            <div class="mt-4 flex flex-wrap gap-3">
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
            @if ($this->connectionUuid !== null)
                <p class="mt-1 text-sm text-gray-500">Jenis koneksi dan provider tidak dapat diubah. Pilih Koneksi lain untuk membuat koneksi baru.</p>
            @endif
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <button type="button" wire:click="$set('type', 'managed_number')" @disabled($this->connectionUuid !== null) @class([
                    'rounded-xl border p-4 text-start',
                    'border-emerald-500 bg-emerald-50 dark:bg-emerald-950/40' => $this->type === 'managed_number',
                    'border-gray-200 dark:border-white/10' => $this->type !== 'managed_number',
                ])>
                    <strong>Pakai nomor WhatsApp saya</strong>
                </button>
                <button type="button" wire:click="$set('type', 'provider_route')" @disabled($this->connectionUuid !== null) @class([
                    'rounded-xl border p-4 text-start',
                    'border-emerald-500 bg-emerald-50 dark:bg-emerald-950/40' => $this->type === 'provider_route',
                    'border-gray-200 dark:border-white/10' => $this->type !== 'provider_route',
                ])>
                    <strong>Pakai provider</strong>
                </button>
            </div>

            @if ($this->type === 'managed_number')
                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <label class="text-sm font-medium">Mode
                        <x-filament::input.wrapper class="mt-1">
                            <x-filament::input.select wire:model.live="mode">
                            <option value="qr">Scan QR</option>
                            <option value="pairing">Kode pairing</option>
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </label>
                    @if ($this->mode === 'pairing')
                        <label class="text-sm font-medium">Nomor HP
                            <x-filament::input.wrapper class="mt-1">
                                <x-filament::input wire:model="phone" />
                            </x-filament::input.wrapper>
                        @error('phone') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                        </label>
                    @endif
                </div>
            @else
                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <label class="text-sm font-medium">Provider
                        <x-filament::input.wrapper class="mt-1">
                            <x-filament::input.select wire:model.live="driver" :disabled="$this->connectionUuid !== null">
                            <option value="fonnte">Fonnte</option>
                            <option value="waha">WAHA</option>
                            <option value="gowa">GOWA</option>
                            <option value="waba">WABA</option>
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </label>
                    @if ($this->driver === 'fonnte')
                        <label class="text-sm font-medium">Token
                            <x-filament::input.wrapper class="mt-1">
                                <x-filament::input type="password" wire:model="token" />
                            </x-filament::input.wrapper>
                        @error('token') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                        </label>
                    @endif
                    @if ($this->driver === 'waha')
                        <label class="text-sm font-medium">Base URL
                            <x-filament::input.wrapper class="mt-1">
                                <x-filament::input wire:model="baseUrl" />
                            </x-filament::input.wrapper>
                        @error('baseUrl') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                        </label>
                        <label class="text-sm font-medium">Session
                            <x-filament::input.wrapper class="mt-1">
                                <x-filament::input wire:model="session" />
                            </x-filament::input.wrapper>
                        @error('session') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                        </label>
                        <label class="text-sm font-medium">API key
                            <x-filament::input.wrapper class="mt-1">
                                <x-filament::input type="password" wire:model="token" />
                            </x-filament::input.wrapper>
                        @error('token') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                        </label>
                    @endif
                    @if ($this->driver === 'gowa')
                        <label class="text-sm font-medium">Base URL
                            <x-filament::input.wrapper class="mt-1">
                                <x-filament::input wire:model="baseUrl" />
                            </x-filament::input.wrapper>
                        @error('baseUrl') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                        </label>
                        <label class="text-sm font-medium">Username
                            <x-filament::input.wrapper class="mt-1">
                                <x-filament::input wire:model="username" />
                            </x-filament::input.wrapper>
                        @error('username') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                        </label>
                        <label class="text-sm font-medium">Password
                            <x-filament::input.wrapper class="mt-1">
                                <x-filament::input type="password" wire:model="password" />
                            </x-filament::input.wrapper>
                        @error('password') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                        </label>
                    @endif
                    @if ($this->driver === 'waba')
                        <label class="text-sm font-medium">Phone number ID
                            <x-filament::input.wrapper class="mt-1">
                                <x-filament::input wire:model="phoneNumberId" />
                            </x-filament::input.wrapper>
                        @error('phoneNumberId') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                        </label>
                        <label class="text-sm font-medium">Access token
                            <x-filament::input.wrapper class="mt-1">
                                <x-filament::input type="password" wire:model="accessToken" />
                            </x-filament::input.wrapper>
                        @error('accessToken') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                        </label>
                    @endif
                </div>
            @endif

            <div class="mt-4 flex flex-wrap gap-3">
                <x-filament::button color="gray" wire:click="$set('step', 1)">Kembali</x-filament::button>
                <x-filament::button wire:click="createConnection">Siapkan koneksi</x-filament::button>
            </div>
        </div>
    @endif

    @if ($this->step === 3)
        <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-white/10 dark:bg-gray-900" @if (($presentation['status'] ?? null) === 'connecting') wire:poll.3s @endif>
            <h3 class="text-lg font-semibold">Status koneksi</h3>
            <p class="mt-1 text-sm text-gray-500">{{ $presentation['recommended_action'] ?? 'Menunggu kesiapan koneksi.' }}</p>
            <p class="mt-3 text-sm"><strong>Status:</strong> {{ $presentation['status_label'] ?? 'Perlu disiapkan' }}</p>
            @if (! empty($presentation['qr']))
                <img src="{{ $presentation['qr'] }}" alt="QR WhatsApp" class="mt-4 h-56 w-56 rounded-lg border" />
            @endif
            @if (! empty($presentation['pairing_code']))
                <p class="mt-4 font-mono text-2xl">{{ $presentation['pairing_code'] }}</p>
            @endif
            <div class="mt-4 flex flex-wrap gap-3">
                <x-filament::button color="gray" wire:click="retrySetup">Coba lagi</x-filament::button>
                @if ($this->connection()?->isManaged() === false)
                    <x-filament::button color="gray" wire:click="editSettings">Ubah pengaturan</x-filament::button>
                @endif
                <x-filament::button color="gray" wire:click="startNewAttempt">Koneksi lain</x-filament::button>
                @if ($this->canSendTest())
                    <x-filament::button wire:click="$set('step', 4)">Kirim pesan uji</x-filament::button>
                @endif
            </div>
        </div>
    @endif

    @if ($this->step === 4)
        <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-white/10 dark:bg-gray-900">
            <h3 class="text-lg font-semibold">Kirim pesan uji</h3>
            <div class="mt-4 grid gap-3 md:grid-cols-2">
                <label class="text-sm font-medium">Nomor tujuan
                    <x-filament::input.wrapper class="mt-1">
                        <x-filament::input wire:model="testRecipient" />
                    </x-filament::input.wrapper>
                        @error('testRecipient') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                </label>
                <label class="text-sm font-medium">Pesan
                    <x-filament::input.wrapper class="mt-1">
                        <x-filament::input wire:model="testText" />
                    </x-filament::input.wrapper>
                        @error('testText') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                </label>
            </div>
            <div class="mt-4 flex flex-wrap gap-3">
                <x-filament::button color="gray" wire:click="$set('step', 3)">Kembali</x-filament::button>
                <x-filament::button wire:click="sendTest">Kirim uji</x-filament::button>
                <x-filament::button color="gray" wire:click="issueIntegration">Lewati ke integrasi</x-filament::button>
            </div>
        </div>
    @endif

    @if ($this->step === 5)
        <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-white/10 dark:bg-gray-900">
            <h3 class="text-lg font-semibold">Salin konfigurasi integrasi</h3>
            <pre class="mt-4 overflow-x-auto rounded-lg bg-gray-950 p-4 text-xs text-gray-100">{{ $this->issuedEnv }}</pre>
            <pre class="mt-4 overflow-x-auto rounded-lg bg-gray-950 p-4 text-xs text-gray-100">wag.sendMessage('081234567890', 'Halo', 'order-1')
# POST $WAG_URL/api/v2/messages</pre>
            <div class="mt-4 flex flex-wrap gap-3">
                <x-filament::button x-on:click="{!! $this->envCopyHandler() !!}">Salin WAG_URL dan WAG_TOKEN</x-filament::button>
                <x-filament::button color="gray" tag="a" :href="\App\Filament\Resources\WhatsAppConnections\WhatsAppConnectionResource::getUrl('index')">
                    Selesai
                </x-filament::button>
            </div>
        </div>
    @endif
</div>
