@php
    $presented = $this->presented();
    $connection = $this->getRecord();
    $health = $presented['health'] ?? [];
    $fallbacks = $connection->isProviderRoute()
        ? app(\App\Services\Connection\ConnectionFallbackManager::class)->listSteps($connection)
        : [];
@endphp

<x-filament-panels::page>
    <div @if ($this->shouldPoll()) wire:poll.3s="poll" @endif class="space-y-6">
        <div class="grid gap-6 lg:grid-cols-3">
            <x-filament::section class="lg:col-span-2">
                <x-slot name="heading">Status koneksi</x-slot>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <p class="text-sm text-gray-500">Nama</p>
                        <p class="font-semibold">{{ $presented['name'] }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Status</p>
                        <x-filament::badge>{{ $presented['status'] }}</x-filament::badge>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Aplikasi</p>
                        <p>{{ $presented['application'] }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Tipe</p>
                        <p>{{ $presented['type'] }}</p>
                    </div>
                    @if (! empty($presented['sender_identity']['phone']))
                        <div>
                            <p class="text-sm text-gray-500">Nomor terhubung</p>
                            <p class="font-mono">{{ $presented['sender_identity']['phone'] }}</p>
                        </div>
                    @endif
                    <div>
                        <p class="text-sm text-gray-500">Tindakan berikutnya</p>
                        <p>{{ $presented['next_action'] ?? '—' }}</p>
                    </div>
                </div>
                @if (! empty($presented['status_detail']))
                    <p class="mt-4 text-sm text-gray-600">{{ $presented['status_detail'] }}</p>
                @endif
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Kesehatan operasional</x-slot>
                <div class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <span>Operasional</span>
                        <x-filament::badge :color="match ($health['operational'] ?? 'unknown') {
                            'ready' => 'success',
                            'degraded' => 'warning',
                            'down' => 'danger',
                            default => 'gray',
                        }">{{ $health['operational'] ?? 'unknown' }}</x-filament::badge>
                    </div>
                    <div class="flex justify-between">
                        <span>Failure rate 24j</span>
                        <span>{{ isset($health['failure_rate_24h']) ? number_format((float) $health['failure_rate_24h'] * 100, 1).'%' : '—' }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Terakhir berhasil kirim</span>
                        <span class="text-right">{{ $health['last_successful_send_at'] ?? '—' }}</span>
                    </div>
                </div>
            </x-filament::section>
        </div>

        @if ($connection->isManagedNumber())
            <x-filament::section>
                <x-slot name="heading">Hubungkan nomor WhatsApp</x-slot>
                <x-slot name="description">Scan QR atau gunakan kode pairing. Status: {{ $this->sessionStatusLabel() }}</x-slot>

                <div class="flex flex-wrap gap-3">
                    <x-filament::button wire:click="startQrSetup" icon="heroicon-o-qr-code">
                        Mulai QR
                    </x-filament::button>
                    <x-filament::button wire:click="startPairingSetup" color="gray" icon="heroicon-o-key">
                        Mulai pairing
                    </x-filament::button>
                </div>

                @if ($qr)
                    <div class="mt-6 flex flex-col items-start gap-4 sm:flex-row sm:items-center">
                        <img src="{{ $qr }}" alt="WhatsApp QR" class="h-56 w-56 rounded-xl border bg-white p-3" />
                        <p class="text-sm text-gray-600">Buka WhatsApp di HP → Perangkat tertaut → Tautkan perangkat → Scan QR.</p>
                    </div>
                @endif

                @if ($pairingCode)
                    <div class="mt-4 rounded-xl border bg-gray-50 p-4">
                        <p class="text-sm text-gray-500">Kode pairing</p>
                        <p class="text-3xl font-mono font-bold tracking-widest">{{ $pairingCode }}</p>
                    </div>
                @endif

                @if ($this->isConnected())
                    <p class="mt-4 text-sm text-success-600">Nomor WhatsApp terhubung. Gunakan tombol "Kirim pesan uji" di atas.</p>
                @endif
            </x-filament::section>
        @endif

        @if ($connection->isProviderRoute())
            <x-filament::section>
                <x-slot name="heading">Validasi provider</x-slot>
                <x-filament::button wire:click="validateProvider" icon="heroicon-o-check-circle">
                    Validasi koneksi
                </x-filament::button>

                @if (count($fallbacks) > 0)
                    <div class="mt-6">
                        <p class="mb-2 text-sm font-medium text-gray-700">Urutan pengiriman (lanjutan)</p>
                        <ol class="list-decimal space-y-1 pl-5 text-sm">
                            @foreach ($fallbacks as $step)
                                <li>{{ $step['slug'] }} <span class="text-gray-500">({{ $step['driver'] }})</span></li>
                            @endforeach
                        </ol>
                    </div>
                @endif
            </x-filament::section>
        @endif

        <x-filament::section>
            <x-slot name="heading">Kemampuan</x-slot>
            <div class="flex flex-wrap gap-2">
                @foreach ($presented['capabilities'] ?? [] as $capability)
                    <x-filament::badge color="gray">{{ $capability }}</x-filament::badge>
                @endforeach
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
