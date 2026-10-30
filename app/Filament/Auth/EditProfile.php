<?php

namespace App\Filament\Auth;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;

// The signed-in person's own profile (name, email, password, two-factor). Its address (/admin/profile) has no clinic
// in it, but the full layout's clinic menu needs one, so the page opens in their default clinic.
class EditProfile extends BaseEditProfile
{
    public function boot(): void
    {
        if (! Filament::getTenant() && ($clinic = Filament::getUserDefaultTenant(auth()->user()))) {
            Filament::setTenant($clinic, isQuiet: true);
        }
    }
}
