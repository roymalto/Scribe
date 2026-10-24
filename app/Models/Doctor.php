<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// A doctor's professional details, shared by every clinic they work in. Only an administrator can mark the PRC
// licence verified, and changing the licence number clears it (database trigger, migration 000100).
class Doctor extends Model
{
    protected $guarded = ['id', 'license_verified_by'];
    protected $casts = ['prc_valid_until' => 'date:Y-m-d', 'license_verified_at' => 'datetime'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function verifiedBy(): BelongsTo { return $this->belongsTo(User::class, 'license_verified_by'); }

    public function isVerified(): bool
    {
        return $this->license_verified_at !== null;
    }

    public function licenseExpired(): bool
    {
        return $this->prc_valid_until !== null && $this->prc_valid_until->isPast();
    }
}
