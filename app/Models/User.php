<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

// Administrators (is_admin) run the platform: every clinic, all accounts, licence verification. Everyone else works in
// the clinics they belong to, with a role in each: CLINIC_ADMIN, DOCTOR or STAFF.
#[Fillable(['name', 'email', 'password', 'is_admin', 'active'])]
#[Hidden(['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery, Notifiable;

    // The database defaults, so a model just created knows them without being reloaded
    protected $attributes = ['is_admin' => false, 'active' => true];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function clinics(): BelongsToMany
    {
        return $this->belongsToMany(Clinic::class)->withPivot('role');
    }

    public function doctor(): HasOne
    {
        return $this->hasOne(Doctor::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->active;
    }

    // The clinics this person can switch between (Filament tenancy)
    public function getTenants(Panel $panel): Collection
    {
        return $this->is_admin ? Clinic::where('active', true)->orderBy('name')->get()
                               : $this->clinics()->where('active', true)->orderBy('name')->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->is_admin || $this->clinics()->whereKey($tenant->getKey())->exists();
    }

    public function isAdmin(): bool
    {
        return $this->is_admin;
    }

    // The role in a clinic; administrators act as clinic admins everywhere
    public function roleIn(Clinic|int|null $clinic): ?string
    {
        if ($this->is_admin) {
            return 'CLINIC_ADMIN';
        }
        $id = $clinic instanceof Clinic ? $clinic->id : $clinic;

        return $id ? $this->clinics()->whereKey($id)->value('role') : null;
    }

    public function hasRoleIn(Clinic|int|null $clinic, string ...$roles): bool
    {
        return in_array($this->roleIn($clinic), $roles, true);
    }
}
