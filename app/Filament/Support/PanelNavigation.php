<?php

namespace App\Filament\Support;

/**
 * Sidebar groups, ordered by the job a Hub operator is doing.
 * Daily work first; engine vs fallback stay apart; bots/alerts/system collapse.
 */
final class PanelNavigation
{
    public const TODAY = 'Hari ini';

    public const OWN_NUMBER = 'Nomor sendiri';

    public const APPS = 'Aplikasi sumber';

    public const FALLBACK = 'OTP & cadangan';

    public const BOTS = 'Bot';

    public const ALERTS = 'Peringatan';

    public const SYSTEM = 'Sistem';
}
