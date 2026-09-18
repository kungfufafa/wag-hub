<?php

namespace App\Providers\Filament;

use App\Filament\Pages\IntegrationDocumentation;
use App\Filament\Support\PanelNavigation;
use App\Filament\Widgets\GatewayStatsOverview;
use Apriansyahrs\MekayaTheme\MekayaPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('panel')
            ->spa()
            ->plugin(
                MekayaPlugin::make()
                    ->documentation(
                        url: '/panel/'.IntegrationDocumentation::getSlug(),
                        label: 'Dokumentasi Integrasi',
                        newTab: false,
                    ),
            )
            ->registration(null)
            ->passwordReset(null)
            ->brandName('WhatsApp Gateway Hub')
            ->brandLogo(asset('icon.svg'))
            ->favicon(asset('icon.svg'))
            ->colors([
                'primary' => Color::Emerald,
            ])
            ->navigationGroups([
                NavigationGroup::make(PanelNavigation::TODAY),
                NavigationGroup::make(PanelNavigation::OWN_NUMBER),
                NavigationGroup::make(PanelNavigation::APPS),
                NavigationGroup::make(PanelNavigation::FALLBACK),
                NavigationGroup::make(PanelNavigation::BOTS)->collapsed(),
                NavigationGroup::make(PanelNavigation::ALERTS)->collapsed(),
                NavigationGroup::make(PanelNavigation::SYSTEM)->collapsed(),
            ])
            ->navigationItems([
                NavigationItem::make('Antrian')
                    ->icon(Heroicon::OutlinedQueueList)
                    ->group(PanelNavigation::SYSTEM)
                    ->sort(10)
                    ->url('/horizon', shouldOpenInNewTab: true),
                NavigationItem::make('Log')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->group(PanelNavigation::SYSTEM)
                    ->sort(20)
                    ->url('/log-viewer', shouldOpenInNewTab: true),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                GatewayStatsOverview::class,
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
