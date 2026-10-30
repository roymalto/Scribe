<?php

namespace App\Services;

use App\Mail\ClinicInvitationMail;
use App\Models\Clinic;
use App\Models\ClinicInvitation;
use App\Models\User;
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

    // True when the mailer doesn't deliver (dev and demo installs write mail to the log), so the inviter has to pass
    // the link on by hand. With a real mail server the link only ever goes to the invited email.
    public static function mailNotDelivered(): bool
    {
        return in_array(config('mail.default'), ['log', 'array'], true);
    }

    // Invite an email address (again: a new link replaces any unanswered one) and email them the link; returns the link
    public function invite(Clinic $clinic, string $email, string $role): string
    {
        $email = strtolower(trim($email));
        $token = Str::random(48);

        DB::transaction(function () use ($clinic, $email, $role, $token) {
            ClinicInvitation::where('clinic_id', $clinic->id)->whereRaw('lower(email) = ?', [$email])
                ->whereNull('accepted_at')->whereNull('declined_at')->delete();

            ClinicInvitation::create([
                'clinic_id' => $clinic->id, 'email' => $email, 'role' => $role, 'token_hash' => self::hash($token),
                'invited_by' => auth()->id(), 'expires_at' => now()->addDays(self::DAYS_VALID),
            ]);
        });

        $link = route('invitations.show', $token);
        Mail::to($email)->send(new ClinicInvitationMail($clinic, $role, $link, auth()->user()?->name));

        return $link;
    }

    // An administrator accepts on the invited person's behalf, first creating their account (name, password) if there
    // is none; returns the clinic's id
    public function acceptFor(ClinicInvitation $invitation, ?string $name = null, ?string $password = null): int
    {
        return DB::transaction(function () use ($invitation, $name, $password) {
            if (! User::whereRaw('lower(email) = ?', [strtolower($invitation->email)])->exists()) {
                User::create(['name' => $name, 'email' => strtolower($invitation->email), 'password' => $password]);
            }

            return (int) DB::selectOne('SELECT scribe.accept_invitation_for(?) AS clinic_id', [$invitation->id])->clinic_id;
        });
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
