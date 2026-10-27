<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// The medicine catalogue: shared items (clinic_id NULL, kept by administrators) and a clinic's own additions (kept by
// its clinic admins). Generic name first, brand optional (Generics Act).
class Medication extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['is_dangerous_drug' => 'boolean', 'active' => 'boolean'];
    protected $attributes = ['is_dangerous_drug' => false, 'active' => true];

    public function clinic(): BelongsTo { return $this->belongsTo(Clinic::class); }

    // The shared catalogue plus one clinic's own items
    public function scopeForClinic(Builder $query, ?int $clinicId): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('clinic_id')->orWhere('clinic_id', $clinicId));
    }

    // "Amoxicillin (Amoxil) 500 mg capsule"
    public function label(): string
    {
        return trim($this->generic_name.($this->brand_name ? " ({$this->brand_name})" : '')
                    .' '.implode(' ', array_filter([$this->strength, $this->dosage_form])));
    }
}
