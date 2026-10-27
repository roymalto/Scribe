<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

// A clinic: its own patients and prescriptions, branding printed on its prescriptions, and staff.
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
    public function invitations(): HasMany { return $this->hasMany(ClinicInvitation::class); }
    public function patients(): HasMany { return $this->hasMany(Patient::class); }
    public function medications(): HasMany { return $this->hasMany(Medication::class); }
    public function prescriptions(): HasMany { return $this->hasMany(Prescription::class); }
}
