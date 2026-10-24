<?php

namespace Tests\Feature;

use App\Mail\ClinicInvitationMail;
use App\Models\Clinic;
use App\Models\ClinicInvitation;
use App\Models\User;
use App\Services\Invitations;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\DatabaseTestCase;

// Nobody joins a clinic without accepting an invitation sent to their email.
class InvitationTest extends DatabaseTestCase
{
    private Clinic $clinic;
    private User $clinicAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->clinic = $this->clinic('Sampaguita Clinic');
        $this->clinicAdmin = $this->user('Liza Admin', roles: [$this->clinic->id => 'CLINIC_ADMIN']);
    }

    // Invite as the clinic admin; returns the token from the emailed link
    private function invite(string $email, string $role = 'DOCTOR'): string
    {
        $this->as($this->clinicAdmin);
        app(Invitations::class)->invite($this->clinic, $email, $role);
        $token = null;
        Mail::assertSent(ClinicInvitationMail::class, function ($m) use ($email, &$token) {
            if ($m->hasTo(strtolower($email))) {
                $token = basename($m->url);
            }

            return true;
        });

        return $token;
    }

    public function test_an_existing_account_accepts_and_joins(): void
    {
        $doctor = $this->user('Dr Elsewhere');
        $token = $this->invite(strtoupper($doctor->email));
        $this->assertNull($doctor->roleIn($this->clinic), 'not a member before accepting');

        $this->actingAs($doctor)->get("/invitations/{$token}")->assertOk()->assertSee('Accept');
        $this->actingAs($doctor)->post("/invitations/{$token}/answer", ['answer' => 'accept'])
            ->assertRedirect('/admin/'.$this->clinic->code);

        $this->as($this->admin);
        $this->assertSame('DOCTOR', $doctor->roleIn($this->clinic));
        $this->assertSame('Accepted', ClinicInvitation::first()->status());
        // a used link can't be used again
        $this->actingAs($doctor)->post("/invitations/{$token}/answer", ['answer' => 'accept'])->assertSessionHasErrors('answer');
    }

    public function test_declining_adds_nobody(): void
    {
        $doctor = $this->user('Dr No');
        $token = $this->invite($doctor->email);
        $this->actingAs($doctor)->post("/invitations/{$token}/answer", ['answer' => 'decline']);

        $this->as($this->admin);
        $this->assertNull($doctor->roleIn($this->clinic));
        $this->assertSame('Declined', ClinicInvitation::first()->status());
    }

    public function test_someone_else_cannot_use_the_link(): void
    {
        $doctor = $this->user('Dr Invited');
        $other = $this->user('Mr Other');
        $token = $this->invite($doctor->email);

        $this->actingAs($other)->get("/invitations/{$token}")->assertSee('Sign out');
        $this->actingAs($other)->post("/invitations/{$token}/answer", ['answer' => 'accept'])
            ->assertSessionHasErrors(['answer' => 'This invitation is for another email address.']);
        $this->as($this->admin);
        $this->assertNull($other->roleIn($this->clinic));
    }

    public function test_a_new_person_creates_an_account_and_joins(): void
    {
        $token = $this->invite('new.nurse@example.test', 'STAFF');
        auth()->logout();
        $this->get("/invitations/{$token}")->assertOk()->assertSee('Create account and accept');

        $this->post("/invitations/{$token}/register", ['name' => 'New Nurse', 'password' => 'a-long-password-1',
                                                       'password_confirmation' => 'a-long-password-1'])
            ->assertRedirect('/admin/'.$this->clinic->code);
        $this->assertAuthenticated();

        $this->as($this->admin);
        $nurse = User::where('email', 'new.nurse@example.test')->firstOrFail();
        $this->assertFalse($nurse->is_admin);
        $this->assertSame('STAFF', $nurse->roleIn($this->clinic));
    }

    public function test_an_existing_account_must_sign_in_rather_than_register_again(): void
    {
        $doctor = $this->user('Dr Has Account');
        $token = $this->invite($doctor->email);
        auth()->logout();

        $this->get("/invitations/{$token}")->assertSee('Sign in');
        $this->post("/invitations/{$token}/register", ['name' => 'Impostor', 'password' => 'a-long-password-1',
                                                       'password_confirmation' => 'a-long-password-1'])
            ->assertSessionHasErrors('name');
        $this->assertSame(1, User::where('email', $doctor->email)->count());
    }

    public function test_an_expired_link_does_nothing(): void
    {
        $doctor = $this->user('Dr Late');
        $token = $this->invite($doctor->email);
        $this->as($this->admin);
        ClinicInvitation::query()->update(['expires_at' => now()->subMinute()]);

        $this->actingAs($doctor)->get("/invitations/{$token}")->assertSee('expired');
        $this->actingAs($doctor)->post("/invitations/{$token}/answer", ['answer' => 'accept'])->assertSessionHasErrors('answer');
        $this->as($this->admin);
        $this->assertNull($doctor->roleIn($this->clinic));
    }

    public function test_sending_again_replaces_the_old_link_and_only_the_hash_is_stored(): void
    {
        $doctor = $this->user('Dr Twice');
        $first = $this->invite($doctor->email);
        Mail::fake();
        $second = $this->invite($doctor->email);

        $this->as($this->admin);
        $this->assertSame(1, ClinicInvitation::count());
        $this->assertSame(hash('sha256', $second), DB::table('clinic_invitations')->value('token_hash'));
        $this->get("/invitations/{$first}")->assertNotFound();
        $this->assertStringNotContainsString($second, (string) DB::table('audit_log')->where('table_name', 'clinic_invitations')
            ->pluck('new_values')->join(' '), 'the token never reaches the audit log');
    }

    public function test_staff_cannot_invite_and_invitees_see_only_their_own(): void
    {
        $staff = $this->user('Joy Staff', roles: [$this->clinic->id => 'STAFF']);
        $this->as($staff);
        try {
            DB::transaction(fn () => ClinicInvitation::create(['clinic_id' => $this->clinic->id, 'email' => 'x@example.test',
                'role' => 'CLINIC_ADMIN', 'token_hash' => str_repeat('a', 64), 'expires_at' => now()->addDay()]));
            $this->fail('staff should not be able to invite');
        } catch (QueryException $e) {
            $this->assertStringContainsString('row-level security', $e->getMessage());
        }

        $doctor = $this->user('Dr Invitee');
        $this->invite($doctor->email);
        $this->invite('someone.else@example.test');
        $this->as($doctor);
        $this->assertSame([strtolower($doctor->email)], ClinicInvitation::pluck('email')->all());
    }
}
