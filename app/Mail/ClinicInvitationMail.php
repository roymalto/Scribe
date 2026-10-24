<?php

namespace App\Mail;

use App\Models\Clinic;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ClinicInvitationMail extends Mailable
{
    use Queueable;

    public function __construct(public Clinic $clinic, public string $role, public string $url, public ?string $inviter) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Invitation to join {$this->clinic->name} on Scribe");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.clinic-invitation', with: [
            'roleLabel' => Clinic::ROLES[$this->role] ?? $this->role,
            'days' => \App\Services\Invitations::DAYS_VALID,
        ]);
    }
}
