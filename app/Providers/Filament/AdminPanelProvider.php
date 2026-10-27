<?php

namespace App\Providers\Filament;

use App\Http\Middleware\SetDatabaseUser;
use App\Filament\Pages\EditClinicProfile;
use App\Filament\Pages\RegisterClinic;
use App\Models\Clinic;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// One panel for the whole platform. Each clinic is a "tenant": its own staff and doctors (and, in later phases, patients
// and prescriptions), switched from the menu, in the address as /admin/{code}/... Administrators see every clinic.
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->profile(isSimple: false)
            ->tenant(Clinic::class, slugAttribute: 'code')
            ->tenantProfile(EditClinicProfile::class)
            ->tenantRegistration(RegisterClinic::class)
            ->brandName('Scribe')
            // Brand files: docs/brand/build_brand.py (same family as SOAP BOX)
            ->brandLogo(fn () => asset('brand/logo.svg'))
            ->darkModeBrandLogo(fn () => asset('brand/logo-white.svg'))
            ->brandLogoHeight('2.25rem')
            ->favicon(asset('brand/icon.svg'))
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => new HtmlString(
                '<link rel="manifest" href="/manifest.webmanifest">'
                .'<link rel="apple-touch-icon" href="/brand/icon-180.png">'
                .'<meta name="theme-color" content="#134e4a">'))
            ->defaultAvatarProvider(\App\Filament\LocalAvatars::class)
            // deep teal, as the logo (Filament's generated palettes stay bright, so the shades are set here)
            ->colors(['primary' => [50 => '#f0fdfa', 100 => '#ccfbf1', 200 => '#99f6e4', 300 => '#5eead4', 400 => '#14b8a6',
                                    500 => '#0f766e', 600 => '#115e59', 700 => '#134e4a', 800 => '#0f3d3a', 900 => '#0b2e2c',
                                    950 => '#042f2e'],
                      'gray' => Color::Zinc])
            // Two-factor sign-in with an authenticator app; administrators must set it up
            ->multiFactorAuthentication([AppAuthentication::make()->recoverable()],
                isRequired: fn () => (bool) auth()->user()?->is_admin)
            ->navigationGroups(['Clinic', 'Platform'])
            ->sidebarCollapsibleOnDesktop()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([\Filament\Pages\Dashboard::class])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                // who is signed in, for row-level security (before the clinic in the address is looked up)
                SetDatabaseUser::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
