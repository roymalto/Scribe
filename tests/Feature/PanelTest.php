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
        foreach (['', '/members', '/doctors', '/profile', '/users', '/clinics'] as $path) {
            $this->get($this->url($path))->assertOk();
        }
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
        $this->get($this->url('/clinics'))->assertForbidden();
    }

    public function test_nobody_opens_a_clinic_they_do_not_work_in(): void
    {
        $other = $this->clinic('Other Clinic');
        $staff = $this->user('Joy Staff', roles: [$other->id => 'STAFF']);
        $this->actingAs($staff);

        $this->get($this->url('/members'))->assertNotFound();
    }

    public function test_a_clinic_admin_adds_a_new_person_and_reuses_an_existing_account(): void
    {
        $clinicAdmin = $this->user('Liza Admin', roles: [$this->clinic->id => 'CLINIC_ADMIN']);
        $existing = $this->user('Dr Elsewhere');
        $this->actingAs($clinicAdmin);
        $this->inPanel();

        \Livewire\Livewire::test(\App\Filament\Resources\Members\Pages\ManageMembers::class)
            ->callAction('create', ['email' => 'new.nurse@example.test', 'name' => 'New Nurse',
                                    'password' => 'a-long-password-1', 'role' => 'STAFF'])
            ->assertHasNoActionErrors()
            ->callAction('create', ['email' => strtoupper($existing->email), 'role' => 'DOCTOR'])
            ->assertHasNoActionErrors();

        $this->as($this->admin);
        $new = User::where('email', 'new.nurse@example.test')->firstOrFail();
        $this->assertFalse($new->is_admin);
        $this->assertSame('STAFF', $new->roleIn($this->clinic));
        $this->assertSame('DOCTOR', $existing->roleIn($this->clinic));
        $this->assertSame(1, User::where('email', $existing->email)->count(), 'no duplicate account');
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
}
