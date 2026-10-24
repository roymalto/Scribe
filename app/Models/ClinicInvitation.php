<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// An invitation to work in a clinic, answered by the invited person through the emailed link (migration 000400)
class ClinicInvitation extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['token_hash'];
    protected $casts = ['expires_at' => 'datetime', 'accepted_at' => 'datetime', 'declined_at' => 'datetime'];

    public function clinic(): BelongsTo { return $this->belongsTo(Clinic::class); }
    public function inviter(): BelongsTo { return $this->belongsTo(User::class, 'invited_by'); }

    public function status(): string
    {
        return match (true) {
            $this->accepted_at !== null => 'Accepted',
            $this->declined_at !== null => 'Declined',
            $this->expires_at->isPast() => 'Expired',
            default => 'Waiting',
        };
    }
}
