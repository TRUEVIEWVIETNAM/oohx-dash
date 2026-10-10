<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class BuyerPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('buyer')
            ->path('buyer')
            ->domain(app()->environment('local') ? null : config('domains.dash'))
            ->login()
            ->colors(['primary' => Color::hex('#2A4FF6')])
            ->brandName('OOHX Buyer')
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups([
                NavigationGroup::make('Chiến dịch')->icon('heroicon-o-megaphone'),
                NavigationGroup::make('Báo cáo')->icon('heroicon-o-chart-bar'),
                NavigationGroup::make('Cài đặt')->icon('heroicon-o-cog-6-tooth'),
            ])
            ->discoverResources(
                in: app_path('Filament/Buyer/Resources'),
                for: 'App\\Filament\\Buyer\\Resources'
            )
            ->discoverPages(
                in: app_path('Filament/Buyer/Pages'),
                for: 'App\\Filament\\Buyer\\Pages'
            )
            ->pages([Dashboard::class])
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
            ->authMiddleware([Authenticate::class])
            ->authGuard('web');
    }
}
