<?php

namespace App\Services;

use App\Mail\ClinicInvitationMail;
use App\Models\Clinic;
use App\Models\ClinicInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

// Invitations to join a clinic: made by its admins, answered by the invited person (see InvitationController).
class Invitations
{
    public const DAYS_VALID = 7;

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    // Invite an email address (again: a new link replaces any unanswered one) and email them the link
    public function invite(Clinic $clinic, string $email, string $role): ClinicInvitation
    {
        $email = strtolower(trim($email));
        $token = Str::random(48);

        $invitation = DB::transaction(function () use ($clinic, $email, $role, $token) {
            ClinicInvitation::where('clinic_id', $clinic->id)->whereRaw('lower(email) = ?', [$email])
                ->whereNull('accepted_at')->whereNull('declined_at')->delete();

            return ClinicInvitation::create([
                'clinic_id' => $clinic->id, 'email' => $email, 'role' => $role, 'token_hash' => self::hash($token),
                'invited_by' => auth()->id(), 'expires_at' => now()->addDays(self::DAYS_VALID),
            ]);
        });

        Mail::to($email)->send(new ClinicInvitationMail($clinic, $role, route('invitations.show', $token),
                                                        auth()->user()?->name));

        return $invitation;
    }

    // What a link is for (anyone holding it may see this): id, clinic_name, email, role, status; or null
    public function lookup(string $token): ?object
    {
        return DB::selectOne('SELECT * FROM scribe.invitation_for_token(?)', [self::hash($token)]);
    }

    // The signed-in person accepts or declines; returns the clinic's id
    public function answer(string $token, bool $accept): int
    {
        // in its own transaction (a savepoint inside another), so a refusal leaves the connection usable
        return DB::transaction(fn () => (int) DB::selectOne('SELECT scribe.answer_invitation(?, ?) AS clinic_id',
                                                            [self::hash($token), $accept])->clinic_id);
    }
}
