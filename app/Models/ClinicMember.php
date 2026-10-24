<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// A person's role in a clinic (table clinic_user): managed by the clinic's admins
class ClinicMember extends Model
{
    protected $table = 'clinic_user';
    public $timestamps = false;
    protected $guarded = ['id'];

    public function clinic(): BelongsTo { return $this->belongsTo(Clinic::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
