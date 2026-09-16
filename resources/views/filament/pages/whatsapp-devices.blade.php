@php
    use App\Domain\WhatsApp\SessionStatus;

    $devices = $this->devices();
    $selected = $this->selected();
    $selectedStatus = $selected?->sessionStatus();

    $badge = static function (SessionStatus $status): array {
        return match ($status->color()) {
            'success' => ['#065f46', '#d1fae5'],
            'warning' => ['#92400e', '#fef3c7'],
            'danger' => ['#991b1b', '#fee2e2'],
            default => ['#374151', '#f3f4f6'],
        };
    };
@endphp

<div class="wa-devices" @if ($selected && ! $selectedStatus->isConnected()) wire:poll.3s="poll" @endif>
    @if ($devices->isEmpty())
        <div class="wa-devices-empty">
            <x-filament::icon icon="heroicon-o-device-phone-mobile" class="wa-devices-empty-icon" />
            <h3>Belum ada engine self-hosted</h3>
            <p>
                Buat <strong>Akun Provider</strong> dengan driver <strong>WAHA</strong> yang menunjuk ke engine
                WAHA/Baileys Anda sendiri, lalu kembali ke sini untuk memindai QR dan menautkan nomor.
            </p>
            <a href="{{ \App\Filament\Resources\ProviderAccounts\ProviderAccountResource::getUrl('create') }}"
               class="wa-devices-empty-cta">
                Tambah akun provider WAHA
            </a>
        </div>
    @else
        <div class="wa-devices-grid">
            <aside class="wa-devices-list">
                @foreach ($devices as $device)
                    @php($status = $this->statusFor($device))
                    @php([$fg, $bg] = $badge($status))
                    <button type="button"
                            wire:click="selectDevice({{ $device->id }})"
                            class="wa-device-item @if ($selected && $selected->id === $device->id) is-active @endif">
                        <span class="wa-device-avatar">
                            <x-filament::icon icon="heroicon-o-device-phone-mobile" class="wa-device-avatar-icon" />
                            <span class="wa-device-dot" style="background: {{ $status->isConnected() ? '#22c55e' : ($status->color() === 'warning' ? '#f59e0b' : '#9ca3af') }}"></span>
                        </span>
                        <span class="wa-device-meta">
                            <span class="wa-device-name">{{ $device->name }}</span>
                            <span class="wa-device-sub">
                                {{ $this->connectedNumber($device) ?? ($device->configuration['session'] ?? $device->slug) }}
                            </span>
                        </span>
                        <span class="wa-device-badge" style="color: {{ $fg }}; background: {{ $bg }};">
                            {{ $status->label() }}
                        </span>
                    </button>
                @endforeach
            </aside>

            <section class="wa-device-detail">
                @if ($selected === null)
                    <div class="wa-device-placeholder">Pilih perangkat di kiri.</div>
                @else
                    @php([$fg, $bg] = $badge($selectedStatus))
                    <header class="wa-device-header">
                        <div>
                            <h2>{{ $selected->name }}</h2>
                            <span class="wa-device-badge" style="color: {{ $fg }}; background: {{ $bg }};">
                                {{ $selectedStatus->label() }}
                            </span>
                        </div>
                        <div class="wa-device-actions">
                            @if ($selectedStatus->isConnected())
                                <button type="button" wire:click="disconnect" class="wa-btn wa-btn-danger" wire:loading.attr="disabled">
                                    Putuskan
                                </button>
                            @else
                                <button type="button" wire:click="connect" class="wa-btn wa-btn-primary" wire:loading.attr="disabled">
                                    <span wire:loading.remove wire:target="connect">Hubungkan</span>
                                    <span wire:loading wire:target="connect">Menghubungkan…</span>
                                </button>
                            @endif
                            <button type="button" wire:click="restart" class="wa-btn wa-btn-ghost" wire:loading.attr="disabled">Restart</button>
                            <button type="button" wire:click="refreshStatus" class="wa-btn wa-btn-ghost" wire:loading.attr="disabled">Segarkan</button>
                        </div>
                    </header>

                    <div class="wa-device-body">
                        @if ($selectedStatus->isConnected())
                            <div class="wa-connected">
                                <x-filament::icon icon="heroicon-o-check-badge" class="wa-connected-icon" />
                                <h3>Terhubung</h3>
                                <p>
                                    Nomor {{ $this->connectedNumber($selected) ?? 'ini' }} sudah tertaut.
                                    Pesan masuk &amp; keluar tersedia di
                                    <a href="{{ \App\Filament\Pages\WhatsAppInbox::getUrl() }}?provider={{ $selected->id }}">Inbox WhatsApp</a>.
                                </p>
                            </div>
                        @elseif ($selectedStatus->needsQr())
                            <div class="wa-qr">
                                <div class="wa-qr-frame">
                                    @if ($qr)
                                        <img src="{{ $qr }}" alt="Kode QR pairing WhatsApp" />
                                    @else
                                        <div class="wa-qr-loading">Menyiapkan QR…</div>
                                    @endif
                                </div>
                                <div class="wa-qr-steps">
                                    <h3>Tautkan perangkat</h3>
                                    <ol>
                                        <li>Buka WhatsApp di ponsel.</li>
                                        <li>Ketuk <strong>Setelan &rsaquo; Perangkat tertaut</strong>.</li>
                                        <li>Ketuk <strong>Tautkan perangkat</strong>.</li>
                                        <li>Arahkan kamera ke kode QR ini.</li>
                                    </ol>
                                    <p class="wa-qr-hint">QR menyegar otomatis. Halaman ini memantau status tiap 3 detik.</p>
                                </div>
                            </div>
                        @else
                            <div class="wa-idle">
                                <p>
                                    Sesi berstatus <strong>{{ $selectedStatus->label() }}</strong>.
                                    Klik <strong>Hubungkan</strong> untuk memulai pairing dan menampilkan kode QR.
                                </p>
                                <p class="wa-idle-note">
                                    Engine: {{ $selected->configuration['base_url'] ?? '—' }} · session
                                    <code>{{ $selected->configuration['session'] ?? '—' }}</code>
                                </p>
                            </div>
                        @endif
                    </div>
                @endif
            </section>
        </div>
    @endif
</div>

<style>
    .wa-devices { --wa-border: var(--mky-border, #e5e7eb); --wa-surface: var(--mky-surface, #fff); }
    .wa-devices-grid { display: grid; grid-template-columns: 320px 1fr; gap: 1rem; align-items: start; }
    @media (max-width: 900px) { .wa-devices-grid { grid-template-columns: 1fr; } }
    .wa-devices-list { display: flex; flex-direction: column; gap: .5rem; }
    .wa-device-item { display: flex; align-items: center; gap: .75rem; width: 100%; text-align: left; padding: .75rem; border: 1px solid var(--wa-border); border-radius: .75rem; background: var(--wa-surface); cursor: pointer; transition: border-color .15s, background .15s; }
    .wa-device-item:hover { border-color: var(--primary-600, #059669); }
    .wa-device-item.is-active { border-color: var(--primary-600, #059669); box-shadow: 0 0 0 1px var(--primary-600, #059669); }
    .wa-device-avatar { position: relative; display: grid; place-items: center; width: 40px; height: 40px; border-radius: 50%; background: #ecfdf5; color: #059669; flex-shrink: 0; }
    .wa-device-avatar-icon { width: 22px; height: 22px; }
    .wa-device-dot { position: absolute; right: -1px; bottom: -1px; width: 12px; height: 12px; border-radius: 50%; border: 2px solid var(--wa-surface); }
    .wa-device-meta { display: flex; flex-direction: column; min-width: 0; flex: 1; }
    .wa-device-name { font-weight: 600; color: var(--mky-text, #111827); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .wa-device-sub { font-size: .8rem; color: #6b7280; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .wa-device-badge { font-size: .72rem; font-weight: 600; padding: .18rem .5rem; border-radius: 999px; white-space: nowrap; }
    .wa-device-detail { border: 1px solid var(--wa-border); border-radius: .75rem; background: var(--wa-surface); min-height: 420px; }
    .wa-device-placeholder { display: grid; place-items: center; height: 420px; color: #6b7280; }
    .wa-device-header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1rem 1.25rem; border-bottom: 1px solid var(--wa-border); flex-wrap: wrap; }
    .wa-device-header h2 { font-size: 1.05rem; font-weight: 700; margin-bottom: .35rem; }
    .wa-device-actions { display: flex; gap: .5rem; flex-wrap: wrap; }
    .wa-btn { font-size: .82rem; font-weight: 600; padding: .45rem .85rem; border-radius: .55rem; cursor: pointer; border: 1px solid transparent; }
    .wa-btn-primary { background: var(--primary-600, #059669); color: #fff; }
    .wa-btn-danger { background: #fee2e2; color: #991b1b; }
    .wa-btn-ghost { background: transparent; border-color: var(--wa-border); color: var(--mky-text, #374151); }
    .wa-btn:disabled { opacity: .6; cursor: progress; }
    .wa-device-body { padding: 1.5rem 1.25rem; }
    .wa-qr { display: flex; gap: 2rem; align-items: center; flex-wrap: wrap; }
    .wa-qr-frame { width: 264px; height: 264px; display: grid; place-items: center; border: 1px solid var(--wa-border); border-radius: 1rem; padding: 12px; background: #fff; }
    .wa-qr-frame img { width: 100%; height: 100%; object-fit: contain; image-rendering: pixelated; }
    .wa-qr-loading { color: #6b7280; }
    .wa-qr-steps h3 { font-weight: 700; margin-bottom: .5rem; }
    .wa-qr-steps ol { margin-left: 1.1rem; list-style: decimal; color: var(--mky-text, #374151); line-height: 1.7; }
    .wa-qr-hint { margin-top: .75rem; font-size: .8rem; color: #6b7280; }
    .wa-connected { display: grid; place-items: center; text-align: center; gap: .25rem; padding: 2rem 0; }
    .wa-connected-icon { width: 56px; height: 56px; color: #059669; }
    .wa-connected h3 { font-size: 1.15rem; font-weight: 700; }
    .wa-connected a, .wa-connected a:visited { color: var(--primary-600, #059669); font-weight: 600; }
    .wa-idle { color: var(--mky-text, #374151); line-height: 1.7; }
    .wa-idle-note { margin-top: .75rem; font-size: .8rem; color: #6b7280; }
    .wa-idle-note code, .wa-idle code { background: #f3f4f6; padding: .1rem .35rem; border-radius: .35rem; }
    .wa-devices-empty { max-width: 520px; margin: 3rem auto; text-align: center; color: var(--mky-text, #374151); }
    .wa-devices-empty-icon { width: 56px; height: 56px; margin: 0 auto 1rem; color: #059669; }
    .wa-devices-empty h3 { font-size: 1.15rem; font-weight: 700; margin-bottom: .5rem; }
    .wa-devices-empty p { line-height: 1.7; }
    .wa-devices-empty-cta { display: inline-block; margin-top: 1rem; background: var(--primary-600, #059669); color: #fff; font-weight: 600; padding: .55rem 1rem; border-radius: .6rem; }
</style>
