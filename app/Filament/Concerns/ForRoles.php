<?php

namespace App\Filament\Concerns;

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

// Which clinic roles may open a screen ($viewRoles) and change things on it ($editRoles). Administrators act as
// CLINIC_ADMIN everywhere. Row-level security in the database enforces the same rules underneath.
trait ForRoles
{
    protected static function role(): ?string
    {
        return auth()->user()?->roleIn(Filament::getTenant());
    }

    public static function canAccess(): bool
    {
        return in_array(static::role(), static::$viewRoles ?? ['CLINIC_ADMIN'], true);
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    protected static function mayEdit(): bool
    {
        return in_array(static::role(), static::$editRoles ?? ['CLINIC_ADMIN'], true);
    }

    public static function canCreate(): bool { return static::mayEdit(); }
    public static function canEdit(Model $record): bool { return static::mayEdit(); }
    public static function canDelete(Model $record): bool { return static::mayEdit(); }
    public static function canDeleteAny(): bool { return static::mayEdit(); }
}
