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
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
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
            ->defaultAvatarProvider(\App\Filament\LocalAvatars::class)
            ->colors(['primary' => Color::Teal, 'gray' => Color::Zinc])
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
