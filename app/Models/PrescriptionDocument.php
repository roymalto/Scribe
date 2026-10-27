<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// A PDF made from an issued prescription's snapshot, kept on the private disk with its SHA-256. Never changed.
class PrescriptionDocument extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['generated_at' => 'datetime'];

    public function prescription(): BelongsTo { return $this->belongsTo(Prescription::class); }
}
