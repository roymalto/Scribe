<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

// A prescription: DRAFT -> ISSUED -> CANCELLED or SUPERSEDED. The database decides who may issue and cancel, locks it
// once issued and builds the printed snapshot (migration 000600); see App\Services\Prescribing.
class Prescription extends Model
{
    protected $guarded = ['id', 'reference', 'issued_at', 'snapshot', 'cancelled_at', 'cancelled_by', 'created_by'];
    protected $casts = ['snapshot' => 'array', 'issued_at' => 'datetime', 'cancelled_at' => 'datetime'];
    protected $attributes = ['status' => 'DRAFT'];

    public const STATUSES = ['DRAFT' => 'Draft', 'ISSUED' => 'Issued', 'CANCELLED' => 'Cancelled', 'SUPERSEDED' => 'Superseded'];
    public const COLORS = ['DRAFT' => 'gray', 'ISSUED' => 'success', 'CANCELLED' => 'danger', 'SUPERSEDED' => 'warning'];

    public function clinic(): BelongsTo { return $this->belongsTo(Clinic::class); }
    public function patient(): BelongsTo { return $this->belongsTo(Patient::class); }
    public function doctor(): BelongsTo { return $this->belongsTo(User::class, 'doctor_user_id'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function cancelledBy(): BelongsTo { return $this->belongsTo(User::class, 'cancelled_by'); }
    public function supersedes(): BelongsTo { return $this->belongsTo(Prescription::class, 'supersedes_id'); }
    public function repeatOf(): BelongsTo { return $this->belongsTo(Prescription::class, 'repeat_of_id'); }
    public function supersededBy(): HasOne { return $this->hasOne(Prescription::class, 'supersedes_id')->where('status', '<>', 'DRAFT'); }
    public function items(): HasMany { return $this->hasMany(PrescriptionItem::class)->orderBy('sort')->orderBy('id'); }
    public function documents(): HasMany { return $this->hasMany(PrescriptionDocument::class)->orderByDesc('id'); }

    public function isDraft(): bool { return $this->status === 'DRAFT'; }
    public function isIssued(): bool { return $this->status === 'ISSUED'; }

    // "Amoxicillin 500 mg capsule, Paracetamol 500 mg tablet"
    public function summary(): string
    {
        return $this->items->map(fn (PrescriptionItem $i) => $i->label())->implode(', ');
    }
}
