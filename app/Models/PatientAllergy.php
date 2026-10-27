<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// A patient's allergy. Prescribing warns (does not block) when a medicine's generic name matches an active one.
class PatientAllergy extends Model
{
    protected $guarded = ['id'];

    public const SEVERITIES = ['MILD' => 'Mild', 'MODERATE' => 'Moderate', 'SEVERE' => 'Severe', 'UNKNOWN' => 'Unknown'];
    public const STATUSES = ['ACTIVE' => 'Active', 'INACTIVE' => 'No longer active'];

    public function patient(): BelongsTo { return $this->belongsTo(Patient::class); }
}
