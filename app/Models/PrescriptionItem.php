<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One medicine on a prescription: copied from the catalogue or typed in. Changes only while the prescription is a draft.
class PrescriptionItem extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['is_dangerous_drug' => 'boolean'];
    protected $attributes = ['is_dangerous_drug' => false];

    public function prescription(): BelongsTo { return $this->belongsTo(Prescription::class); }
    public function medication(): BelongsTo { return $this->belongsTo(Medication::class); }

    public function label(): string
    {
        return trim($this->generic_name.($this->brand_name ? " ({$this->brand_name})" : '')
                    .' '.implode(' ', array_filter([$this->strength, $this->dosage_form])));
    }
}
