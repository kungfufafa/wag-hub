@php
    use App\Domain\WhatsApp\SessionStatus;

    $devices = $this->devices();
    $hosts = $this->hostDevices();
    $linked = $this->linkedDevices();
    $selected = $this->selected();
    $selectedStatus = $selected?->sessionStatus();
    $selectedLinked = $selected?->isUserLinkedSession() ?? false;
@endphp

<div class="wa-devices" @if ($selected && ! $selectedStatus->isConnected()) wire:poll.3s="poll" @endif>
    @if ($devices->isEmpty())
        <div class="wa-devices-empty">
            <x-filament::icon icon="heroicon-o-device-phone-mobile" class="wa-devices-empty-icon" />
            <h3 class="wa-devices-empty-title">Tidak ada akun WAHA</h3>
            <p class="wa-devices-empty-text">
                Buka <strong>Akun Provider</strong>, pilih driver <strong>WAHA</strong>,
                isi base URL dan API key.
            </p>
            <x-filament::button tag="a" icon="heroicon-o-plus"
                :href="\App\Filament\Resources\ProviderAccounts\ProviderAccountResource::getUrl('create')">
                Tambah akun provider WAHA
            </x-filament::button>
        </div>
    @else
        <div class="wa-devices-grid">
            <aside class="wa-devices-col wa-devices-list">
                <div class="wa-devices-head">
                    <h2 class="wa-devices-heading">Perangkat</h2>
                    <p class="wa-devices-sub">Akun driver WAHA di provider_accounts.</p>
                </div>
                <div class="wa-devices-scroll">
                    @if ($hosts->isNotEmpty())
                        <p class="wa-devices-group">Host WAHA</p>
                        @foreach ($hosts as $device)
                            @include('filament.pages.partials.whatsapp-device-item', ['device' => $device, 'selected' => $selected])
                        @endforeach
                    @endif
                    @if ($linked->isNotEmpty())
                        <p class="wa-devices-group">Sesi /engine</p>
                        @foreach ($linked as $device)
                            @include('filament.pages.partials.whatsapp-device-item', ['device' => $device, 'selected' => $selected])
                        @endforeach
                    @endif
                    @if ($hosts->isEmpty() && $linked->isEmpty())
                        @foreach ($devices as $device)
                            @include('filament.pages.partials.whatsapp-device-item', ['device' => $device, 'selected' => $selected])
                        @endforeach
                    @endif
                </div>
            </aside>

            <section class="wa-devices-col wa-devices-detail">
                @if ($selected === null)
                    <div class="wa-devices-placeholder">Pilih perangkat di kiri.</div>
                @else
                    <div class="wa-devices-head wa-devices-detail-head">
                        <div class="wa-devices-detail-title">
                            <h2 class="wa-devices-heading">{{ $selected->name }}</h2>
                            <x-filament::badge :color="$selectedStatus->color()">{{ $selectedStatus->label() }}</x-filament::badge>
                        </div>
                        <div class="wa-devices-actions">
                            @if ($selectedStatus->isConnected())
                                <x-filament::button wire:click="disconnect" color="danger" size="sm"
                                    icon="heroicon-o-arrow-right-on-rectangle" wire:loading.attr="disabled">
                                    Putuskan
                                </x-filament::button>
                            @else
                                <x-filament::button wire:click="connect" size="sm" icon="heroicon-o-qr-code"
                                    wire:target="connect" wire:loading.attr="disabled">
                                    Hubungkan
                                </x-filament::button>
                            @endif
                            <x-filament::button wire:click="restart" color="gray" size="sm"
                                icon="heroicon-o-arrow-path" wire:loading.attr="disabled">
                                Restart
                            </x-filament::button>
                        </div>
                    </div>

                    <div class="wa-devices-body">
                        @if ($selectedStatus->isConnected())
                            <div class="wa-devices-state wa-devices-connected">
                                <span class="wa-devices-state-icon is-ok">
                                    <x-filament::icon icon="heroicon-o-check-badge" />
                                </span>
                                <h3 class="wa-devices-state-title">Terhubung</h3>
                                <p class="wa-devices-state-text">
                                    {{ $this->connectedNumber($selected) ?? $selected->configuration['session'] ?? $selected->slug }}
                                    WORKING.
                                </p>
                                <x-filament::button tag="a" size="sm" icon="heroicon-o-inbox"
                                    :href="\App\Filament\Pages\WhatsAppInbox::getUrl() . '?provider=' . $selected->id">
                                    Buka Inbox WhatsApp
                                </x-filament::button>
                            </div>
                        @elseif ($selectedStatus->needsQr())
                            <div class="wa-devices-qr">
                                <div class="wa-devices-qr-frame">
                                    @if ($this->qr)
                                        <img src="{{ $this->qr }}" alt="Kode QR pairing WhatsApp" />
                                    @else
                                        <div class="wa-devices-qr-loading">
                                            <x-filament::loading-indicator class="wa-devices-qr-spinner" />
                                            <span>Menyiapkan QR…</span>
                                        </div>
                                    @endif
                                </div>
                                <div class="wa-devices-qr-steps">
                                    <h3 class="wa-devices-state-title">Scan di HP</h3>
                                    <ol>
                                        <li>WhatsApp → <strong>Perangkat tertaut</strong>.</li>
                                        <li>Ketuk <strong>Tautkan perangkat</strong>.</li>
                                        <li>Arahkan kamera ke QR ini.</li>
                                    </ol>
                                    <p class="wa-devices-hint">QR menyegar otomatis.</p>
                                </div>
                            </div>
                        @else
                            <div class="wa-devices-state wa-devices-idle">
                                <span class="wa-devices-state-icon">
                                    <x-filament::icon icon="heroicon-o-qr-code" />
                                </span>
                                <p class="wa-devices-state-text">
                                    {{ $selectedStatus->label() }}.
                                    Session
                                    <code>{{ $selected->configuration['session'] ?? $selected->slug }}</code>.
                                </p>
                                @unless ($selectedLinked)
                                    <p class="wa-devices-meta">
                                        base_url {{ $selected->configuration['base_url'] ?? '—' }}
                                    </p>
                                @endunless
                            </div>
                        @endif
                    </div>
                @endif
            </section>
        </div>
    @endif
</div>

<style>
    .fi-sc:has(.wa-devices),
    .fi-grid:has(.wa-devices) {
        padding: 0;
        gap: 0;
        max-width: none;
    }

    .fi-section:has(.wa-devices) > .fi-section-content-ctn,
    .fi-section:has(.wa-devices) .fi-section-content,
    .fi-section:has(.wa-devices) .fi-sc-component {
        padding: 0;
        overflow: hidden;
    }

    .wa-devices {
        --wa-line: var(--mky-border, var(--gray-200));
        --wa-muted: var(--gray-500);
        --wa-panel: var(--mky-surface-muted, var(--gray-50));
        --wa-radius: var(--mky-radius-surface, 0.75rem);
        box-sizing: border-box;
        width: 100%;
        overflow: hidden;
        border: 1px solid var(--wa-line);
        border-radius: var(--wa-radius);
        background: var(--mky-surface, white);
        color: inherit;
        font-size: 0.875rem;
        line-height: 1.45;
    }

    .wa-devices *, .wa-devices *::before, .wa-devices *::after { box-sizing: border-box; }
    .wa-devices h2, .wa-devices h3, .wa-devices p, .wa-devices ol { margin: 0; }

    .wa-devices-grid {
        display: grid;
        grid-template-columns: minmax(15rem, 20rem) minmax(0, 1fr);
        min-height: min(64vh, 40rem);
    }

    @media (max-width: 860px) {
        .wa-devices-grid { grid-template-columns: 1fr; }
    }

    .wa-devices-col { display: flex; min-width: 0; flex-direction: column; }
    .wa-devices-detail { border-left: 1px solid var(--wa-line); }
    @media (max-width: 860px) {
        .wa-devices-detail { border-left: 0; border-top: 1px solid var(--wa-line); }
    }

    .wa-devices-head {
        display: grid;
        gap: 0.15rem;
        padding: 0.85rem 1rem;
        border-bottom: 1px solid var(--wa-line);
    }

    .wa-devices-heading {
        font-family: var(--font-heading, inherit);
        font-size: 0.95rem;
        font-weight: 600;
        color: var(--gray-950);
    }

    .dark .wa-devices-heading { color: white; }
    .wa-devices-sub { font-size: 0.8rem; color: var(--wa-muted); }

    .wa-devices-scroll { flex: 1; min-height: 0; overflow: auto; }
    .wa-devices-group {
        margin: 0;
        padding: 0.7rem 1rem 0.25rem;
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: var(--wa-muted);
    }

    .wa-devices-item {
        display: flex;
        align-items: center;
        gap: 0.7rem;
        width: 100%;
        border: 0;
        border-bottom: 1px solid var(--wa-line);
        padding: 0.7rem 1rem;
        background: transparent;
        text-align: left;
        cursor: pointer;
        appearance: none;
        font: inherit;
        color: inherit;
    }

    .wa-devices-item:hover { background: var(--wa-panel); }
    .wa-devices-item.is-active { background: var(--gray-100); }
    .dark .wa-devices-item.is-active { background: color-mix(in oklab, var(--gray-800) 70%, transparent); }
    .wa-devices-item:focus-visible { outline: 2px solid var(--primary-500); outline-offset: -2px; }

    .wa-devices-avatar {
        position: relative;
        flex: none;
        display: grid;
        place-items: center;
        width: 2.25rem;
        height: 2.25rem;
        border-radius: 999px;
        background: var(--gray-100);
        color: var(--gray-500);
    }
    .dark .wa-devices-avatar { background: var(--gray-800); color: var(--gray-300); }
    .wa-devices-avatar.is-on { background: color-mix(in oklab, var(--primary-500) 16%, transparent); color: var(--primary-600); }
    .wa-devices-avatar-icon { width: 1.2rem; height: 1.2rem; }

    .wa-devices-dot {
        position: absolute;
        right: -1px;
        bottom: -1px;
        width: 0.7rem;
        height: 0.7rem;
        border-radius: 999px;
        background: var(--gray-400);
        border: 2px solid var(--mky-surface, white);
    }
    .dark .wa-devices-dot { border-color: var(--gray-900); }
    .wa-devices-dot.is-on { background: rgb(34 197 94); }
    .wa-devices-dot.is-wait { background: rgb(245 158 11); }

    .wa-devices-item-body { display: grid; gap: 0.1rem; min-width: 0; flex: 1; }
    .wa-devices-item-name { font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .wa-devices-item-sub { font-size: 0.78rem; color: var(--wa-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

    .wa-devices-placeholder { display: grid; place-items: center; flex: 1; color: var(--wa-muted); padding: 2rem; }

    .wa-devices-detail-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
    }
    .wa-devices-detail-title { display: flex; align-items: center; gap: 0.6rem; min-width: 0; }
    .wa-devices-actions { display: flex; gap: 0.4rem; flex-wrap: wrap; }

    .wa-devices-body { flex: 1; min-height: 0; overflow: auto; padding: 1.5rem 1.25rem; }

    .wa-devices-state { display: grid; place-items: center; text-align: center; gap: 0.6rem; max-width: 26rem; margin: 1.5rem auto; }
    .wa-devices-state-icon {
        display: grid; place-items: center;
        width: 3.5rem; height: 3.5rem; border-radius: 999px;
        background: var(--gray-100); color: var(--gray-500);
    }
    .dark .wa-devices-state-icon { background: var(--gray-800); color: var(--gray-300); }
    .wa-devices-state-icon svg { width: 1.9rem; height: 1.9rem; }
    .wa-devices-state-icon.is-ok { background: color-mix(in oklab, var(--primary-500) 16%, transparent); color: var(--primary-600); }
    .wa-devices-state-title { font-size: 1.05rem; font-weight: 700; color: var(--gray-950); }
    .dark .wa-devices-state-title { color: white; }
    .wa-devices-state-text { color: var(--wa-muted); }

    .wa-devices-qr { display: flex; gap: 2rem; align-items: center; flex-wrap: wrap; justify-content: center; }
    .wa-devices-qr-frame {
        flex: none;
        width: 15rem; height: 15rem;
        display: grid; place-items: center;
        border: 1px solid var(--wa-line);
        border-radius: 1rem;
        padding: 0.75rem;
        background: white;
    }
    .wa-devices-qr-frame img { width: 100%; height: 100%; object-fit: contain; image-rendering: pixelated; }
    .wa-devices-qr-loading { display: grid; gap: 0.5rem; place-items: center; color: var(--gray-500); }
    .wa-devices-qr-spinner { width: 1.5rem; height: 1.5rem; }
    .wa-devices-qr-steps { min-width: 14rem; max-width: 20rem; }
    .wa-devices-qr-steps ol { margin-top: 0.5rem; margin-left: 1.1rem; list-style: decimal; line-height: 1.8; }
    .wa-devices-hint { margin-top: 0.75rem; font-size: 0.78rem; color: var(--wa-muted); }

    .wa-devices-meta { margin-top: 0.35rem; font-size: 0.78rem; color: var(--wa-muted); }
    .wa-devices code {
        background: var(--gray-100); color: var(--gray-700);
        padding: 0.08rem 0.35rem; border-radius: 0.35rem; font-size: 0.78rem;
    }
    .dark .wa-devices code { background: var(--gray-800); color: var(--gray-200); }

    .wa-devices-empty { display: grid; place-items: center; text-align: center; gap: 0.5rem; max-width: 32rem; margin: 3rem auto; padding: 1rem; }
    .wa-devices-empty-icon { width: 3rem; height: 3rem; color: var(--primary-600); }
    .wa-devices-empty-title { font-size: 1.05rem; font-weight: 700; color: var(--gray-950); }
    .dark .wa-devices-empty-title { color: white; }
    .wa-devices-empty-text { color: var(--wa-muted); line-height: 1.7; margin-bottom: 0.5rem; }
</style>
