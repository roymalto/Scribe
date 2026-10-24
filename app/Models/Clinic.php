<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

// A clinic: its own patients and prescriptions (later phases), branding printed on its prescriptions, and staff.
class Clinic extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['active' => 'boolean'];

    public const ROLES = ['CLINIC_ADMIN' => 'Clinic admin', 'DOCTOR' => 'Doctor', 'STAFF' => 'Staff (secretary, nurse)'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role');
    }

    public function members(): HasMany { return $this->hasMany(ClinicMember::class); }
}
