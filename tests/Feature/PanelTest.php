<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\ClinicMember;
use App\Models\Doctor;
use App\Models\User;
use Tests\DatabaseTestCase;

// The screens open for the people who should see them, and not for others.
class PanelTest extends DatabaseTestCase
{
    private Clinic $clinic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clinic = $this->clinic('Sampaguita Clinic');
        // administrators must use two-factor sign-in: give the test one so the panel doesn't send it to the setup
        $this->admin->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
    }

    private function url(string $path = ''): string
    {
        return '/admin/'.$this->clinic->code.$path;
    }

    // Livewire tests call the page directly: tell Filament which panel and clinic it is in
    private function inPanel(): void
    {
        \Filament\Facades\Filament::setCurrentPanel('admin');
        \Filament\Facades\Filament::setTenant($this->clinic);
        \Filament\Facades\Filament::bootCurrentPanel();
    }

    public function test_the_administrator_opens_every_screen(): void
    {
        $this->actingAs($this->admin);
        foreach (['', '/members', '/invitations', '/doctors', '/profile', '/users', '/clinics'] as $path) {
            $this->get($this->url($path))->assertOk();
        }
        $this->get('/admin/profile')->assertOk();
        $this->get($this->url())->assertSee('Clinic details');
    }

    public function test_staff_see_the_clinic_but_not_its_settings_or_the_platform(): void
    {
        $staff = $this->user('Joy Staff', roles: [$this->clinic->id => 'STAFF']);
        $this->actingAs($staff);

        $this->get($this->url())->assertOk();
        $this->get($this->url('/members'))->assertOk();
        $this->get($this->url('/doctors'))->assertOk();
        $this->get($this->url('/profile'))->assertNotFound();
        $this->get($this->url('/users'))->assertForbidden();
        $this->get('/admin/profile')->assertOk()->assertSee('Joy Staff');      // their own profile, in their clinic
        $this->get($this->url())->assertDontSee('Clinic details');
        $this->get($this->url('/clinics'))->assertForbidden();
    }

    public function test_nobody_opens_a_clinic_they_do_not_work_in(): void
    {
        $other = $this->clinic('Other Clinic');
        $staff = $this->user('Joy Staff', roles: [$other->id => 'STAFF']);
        $this->actingAs($staff);

        $this->get($this->url('/members'))->assertNotFound();
    }

    public function test_a_clinic_admin_invites_from_the_staff_page(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $clinicAdmin = $this->user('Liza Admin', roles: [$this->clinic->id => 'CLINIC_ADMIN']);
        $this->actingAs($clinicAdmin);
        $this->inPanel();

        \Livewire\Livewire::test(\App\Filament\Resources\Members\Pages\ManageMembers::class)
            ->assertActionDoesNotExist('create')
            ->callAction('invite', ['email' => 'New.Nurse@example.test', 'role' => 'STAFF'])
            ->assertHasNoActionErrors();

        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\ClinicInvitationMail::class,
            fn ($m) => $m->hasTo('new.nurse@example.test') && str_contains($m->url, '/invitations/'));
        $this->as($this->admin);
        $this->assertSame('Waiting', \App\Models\ClinicInvitation::where('email', 'new.nurse@example.test')->first()->status());
        $this->assertNull(User::where('email', 'new.nurse@example.test')->first(), 'no account until they accept');
    }

    public function test_only_the_administrator_gets_accept_for_them_on_the_screen(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $clinicAdmin = $this->user('Liza Admin', roles: [$this->clinic->id => 'CLINIC_ADMIN']);
        $this->actingAs($clinicAdmin);
        $this->inPanel();
        app(\App\Services\Invitations::class)->invite($this->clinic, 'dr.screen@example.test', 'DOCTOR');
        $inv = \App\Models\ClinicInvitation::firstOrFail();
        $acceptFor = \Filament\Actions\Testing\TestAction::make('acceptFor')->table($inv);

        \Livewire\Livewire::test(\App\Filament\Resources\Invitations\Pages\ManageInvitations::class)
            ->assertActionHidden($acceptFor);

        $this->actingAs($this->admin);
        \Livewire\Livewire::test(\App\Filament\Resources\Invitations\Pages\ManageInvitations::class)
            ->assertActionVisible($acceptFor)
            ->callAction($acceptFor, ['name' => 'Dr Screen', 'password' => 'a-long-password-1'])
            ->assertHasNoActionErrors()
            ->assertSee('by Test Admin for them');

        $this->as($this->admin);
        $this->assertSame('DOCTOR', User::where('email', 'dr.screen@example.test')->firstOrFail()->roleIn($this->clinic));
    }

    public function test_the_profile_opens_in_the_clinic_they_were_working_in(): void
    {
        $zinnia = $this->clinic('Zinnia Clinic');                 // sorts after the setUp clinic
        $doctor = $this->user('Ramon Doctor', roles: [$this->clinic->id => 'DOCTOR', $zinnia->id => 'DOCTOR']);
        $this->actingAs($doctor);

        $this->get('/admin/'.$zinnia->code.'/doctors')->assertOk();
        $this->get('/admin/profile')->assertOk()->assertSee('/admin/'.$zinnia->code.'/patients', escape: false);
    }

    public function test_staff_cannot_open_invitations(): void
    {
        $this->actingAs($this->user('Joy Staff', roles: [$this->clinic->id => 'STAFF']));
        $this->get($this->url('/invitations'))->assertForbidden();
    }

    public function test_a_doctor_enters_their_own_licence_details(): void
    {
        $doctor = $this->user('Ramon Doctor', roles: [$this->clinic->id => 'DOCTOR']);
        $this->actingAs($doctor);
        $this->inPanel();

        \Livewire\Livewire::test(\App\Filament\Resources\Doctors\Pages\ManageDoctors::class)
            ->callAction('create', ['user_id' => $doctor->id, 'display_name' => 'Ramon Doctor, MD', 'prc_license_no' => '0123456'])
            ->assertHasNoActionErrors();

        $this->as($this->admin);
        $this->assertSame('0123456', Doctor::where('user_id', $doctor->id)->value('prc_license_no'));
        $this->assertFalse(Doctor::where('user_id', $doctor->id)->first()->isVerified());
    }

    public function test_only_the_doctor_sees_their_signature_upload_and_edits_only_their_own(): void
    {
        $ramon = $this->user('Ramon Doctor', roles: [$this->clinic->id => 'DOCTOR']);
        $celia = $this->user('Celia Doctor', roles: [$this->clinic->id => 'DOCTOR']);
        $clinicAdmin = $this->user('Liza Admin', roles: [$this->clinic->id => 'CLINIC_ADMIN']);
        $this->as($this->admin);
        $own = Doctor::create(['user_id' => $ramon->id, 'display_name' => 'Ramon Doctor, MD', 'prc_license_no' => '0123456']);
        $other = Doctor::create(['user_id' => $celia->id, 'display_name' => 'Celia Doctor, MD', 'prc_license_no' => '0654321']);
        $edit = fn (Doctor $d) => \Filament\Actions\Testing\TestAction::make('edit')->table($d);
        $page = \App\Filament\Resources\Doctors\Pages\ManageDoctors::class;

        $this->actingAs($ramon);
        $this->inPanel();
        \Livewire\Livewire::test($page)->assertActionHidden($edit($other))
            ->mountAction($edit($own))->assertFormFieldVisible('signature_path');

        $this->actingAs($clinicAdmin);
        \Livewire\Livewire::test($page)->mountAction($edit($own))->assertFormFieldHidden('signature_path');
    }
}
