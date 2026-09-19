<?php

namespace App\Filament\Support;

/**
 * Sidebar group labels. Names match the screens under them.
 */
final class PanelNavigation
{
    public const WHATSAPP = 'WhatsApp';

    public const DEVICES = 'Perangkat';

    public const APPS = 'Aplikasi';

    /** Advanced operator controls: provider accounts, routing tables, diagnostics. */
    public const ADVANCED = 'Lanjutan';

    /** @deprecated Use ADVANCED */
    public const PROVIDERS = self::ADVANCED;

    public const AUTOMATION = 'Automasi';

    public const ALERTS = 'Alert';

    public const SERVER = 'Server';
}
