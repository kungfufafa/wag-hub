<x-filament-panels::page>
    <div class="mx-auto max-w-3xl space-y-6">
        <x-filament::section>
            <x-slot name="heading">Hubungkan WhatsApp ke aplikasi</x-slot>
            <x-slot name="description">
                Alur singkat: pilih aplikasi → pilih cara menghubungkan → buat koneksi → uji kirim → salin konfigurasi integrasi.
                Provider account, routing policy, dan route key disiapkan otomatis di belakang layar.
            </x-slot>

            <form wire:submit="createConnection" class="space-y-6">
                {{ $this->form }}

                <div class="flex justify-end">
                    <x-filament::button type="submit" icon="heroicon-o-plus">
                        Buat koneksi
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>
    </div>
</x-filament-panels::page>
