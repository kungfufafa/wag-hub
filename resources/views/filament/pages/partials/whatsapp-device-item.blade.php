@php
    use App\Domain\WhatsApp\SessionStatus;

    $status = $this->statusFor($device);
@endphp

<button type="button"
        wire:click="selectDevice({{ $device->id }})"
        class="wa-devices-item @if ($selected && $selected->id === $device->id) is-active @endif">
    <span class="wa-devices-avatar" @class(['is-on' => $status->isConnected()])>
        <x-filament::icon icon="heroicon-o-device-phone-mobile" class="wa-devices-avatar-icon" />
        <span class="wa-devices-dot" @class([
            'is-on' => $status->isConnected(),
            'is-wait' => in_array($status, [SessionStatus::ScanQr, SessionStatus::Starting], true),
        ])></span>
    </span>
    <span class="wa-devices-item-body">
        <span class="wa-devices-item-name">{{ $device->name }}</span>
        <span class="wa-devices-item-sub">
            {{ $this->connectedNumber($device) ?? ($device->configuration['session'] ?? $device->slug) }}
        </span>
    </span>
    <x-filament::badge :color="$status->color()" size="sm">{{ $status->label() }}</x-filament::badge>
</button>
