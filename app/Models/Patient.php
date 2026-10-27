<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// A clinic's patient. The patient number (P-000001, ...) is given by the database when left empty (migration 000500).
class Patient extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['birth_date' => 'date:Y-m-d'];

    public const SEXES = ['F' => 'Female', 'M' => 'Male'];

    public function clinic(): BelongsTo { return $this->belongsTo(Clinic::class); }
    public function allergies(): HasMany { return $this->hasMany(PatientAllergy::class); }
    public function prescriptions(): HasMany { return $this->hasMany(Prescription::class); }

    public function activeAllergies(): HasMany
    {
        return $this->allergies()->where('status', 'ACTIVE');
    }

    // Last, First Middle
    public function fullName(): string
    {
        return trim("{$this->last_name}, {$this->first_name} ".($this->middle_name ?? ''));
    }

    public function age(): ?int
    {
        return $this->birth_date?->age;
    }
}
