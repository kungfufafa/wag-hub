@php
    $connection = $this->connection();
    $presented = $connection ? \App\Filament\Resources\WhatsAppConnections\WhatsAppConnectionResource::presentRecord($connection) : null;
    $diagnostics = $connection ? app(\App\Services\Connection\ConnectionDiagnosticsService::class)->summarize($connection) : null;
@endphp

<x-filament-panels::page>
    <div @if ($this->shouldPoll()) wire:poll.3s="poll" @endif class="mx-auto max-w-3xl space-y-6">
        <x-filament::section>
            <x-slot name="heading">Hubungkan WhatsApp ke aplikasi</x-slot>
            <x-slot name="description">
                Create App → Connect WhatsApp → Test → Copy configuration. Provider account, routing policy, dan route key disiapkan otomatis.
            </x-slot>

            <div class="mb-6 flex gap-2 text-sm">
                @foreach ([1 => 'Buat', 2 => 'Hubungkan', 3 => 'Uji', 4 => 'Integrasi'] as $step => $label)
                    <span @class([
                        'rounded-full px-3 py-1',
                        'bg-primary-500 text-white' => $wizardStep === $step,
                        'bg-gray-100 text-gray-600' => $wizardStep !== $step,
                    ])>{{ $step }}. {{ $label }}</span>
                @endforeach
            </div>

            @if ($wizardStep === 1)
                <form wire:submit="createConnection" class="space-y-6">
                    {{ $this->form }}
                    <div class="flex justify-end">
                        <x-filament::button type="submit" icon="heroicon-o-arrow-right">
                            Lanjutkan
                        </x-filament::button>
                    </div>
                </form>
            @endif

            @if ($wizardStep >= 2 && $connection)
                <div class="space-y-4">
                    <div class="rounded-lg border p-4">
                        <p class="font-semibold">{{ $presented['name'] ?? $connection->name }}</p>
                        <p class="text-sm text-gray-500">Status: {{ $presented['status'] ?? $connection->status }}</p>
                    </div>

                    @if ($wizardStep === 2)
                        @if ($connection->isManagedNumber())
                            <div class="flex flex-wrap gap-3">
                                <x-filament::button wire:click="startSetup('qr')" icon="heroicon-o-qr-code">Mulai QR</x-filament::button>
                                <x-filament::button wire:click="startSetup('pairing')" color="gray" icon="heroicon-o-key">Mulai pairing</x-filament::button>
                            </div>
                            @if ($qr)
                                <img src="{{ $qr }}" alt="WhatsApp QR" class="mt-4 h-56 w-56 rounded-xl border bg-white p-3" />
                            @endif
                            @if ($pairingCode)
                                <p class="mt-4 font-mono text-2xl tracking-widest">{{ $pairingCode }}</p>
                            @endif
                        @else
                            <x-filament::button wire:click="validateProvider" icon="heroicon-o-check-circle">Validasi provider</x-filament::button>
                        @endif

                        @if ($connection->connectionStatus()->canSend())
                            <div class="pt-4">
                                <x-filament::button wire:click="$set('wizardStep', 3)" icon="heroicon-o-arrow-right">Lanjut ke uji kirim</x-filament::button>
                            </div>
                        @endif
                    @endif

                    @if ($wizardStep === 3)
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="text-sm font-medium">Nomor penerima uji</label>
                                <input type="text" wire:model="testRecipient" class="mt-1 w-full rounded-lg border-gray-300" placeholder="6281234567890" />
                            </div>
                            <div>
                                <label class="text-sm font-medium">Pesan</label>
                                <input type="text" wire:model="testText" class="mt-1 w-full rounded-lg border-gray-300" />
                            </div>
                        </div>
                        <div class="flex gap-3 pt-2">
                            <x-filament::button wire:click="sendTestMessage" icon="heroicon-o-paper-airplane">Kirim pesan uji</x-filament::button>
                            <x-filament::button wire:click="skipToIntegration" color="gray">Lewati</x-filament::button>
                        </div>
                    @endif

                    @if ($wizardStep === 4)
                        <div>
                            <label class="text-sm font-medium">Konfigurasi integrasi</label>
                            <textarea readonly rows="4" class="mt-1 w-full rounded-lg border-gray-300 font-mono text-xs">{{ $integrationEnv }}</textarea>
                        </div>
                        <x-filament::button tag="a" :href="\App\Filament\Resources\WhatsAppConnections\Pages\ViewWhatsAppConnection::getUrl(['record' => $connection])" icon="heroicon-o-eye">
                            Buka detail koneksi
                        </x-filament::button>
                    @endif
                </div>
            @endif
        </x-filament::section>

        @if ($diagnostics)
            <x-filament::section>
                <x-slot name="heading">Diagnostik (24 jam)</x-slot>
                <div class="grid gap-4 text-sm sm:grid-cols-4">
                    <div>Total: {{ $diagnostics['messages']['total'] }}</div>
                    <div>Berhasil: {{ $diagnostics['messages']['accepted'] }}</div>
                    <div>Gagal: {{ $diagnostics['messages']['failed'] }}</div>
                    <div>Unknown: {{ $diagnostics['messages']['outcome_unknown'] }}</div>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
