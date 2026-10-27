<?php

namespace Tests\Feature;

use App\Filament\Resources\Medications\Pages\ManageMedications;
use App\Filament\Resources\Patients\Pages\CreatePatient;
use App\Filament\Resources\Prescriptions\Pages\CreatePrescription;
use App\Filament\Resources\Prescriptions\Pages\ViewPrescription;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\DatabaseTestCase;

// Phase 2 and 3 screens: patients, medicines and prescriptions open for the clinic's people, and the prescribing flow
// works through them (staff prepare, the doctor issues).
class PrescribingScreensTest extends DatabaseTestCase
{
    private Clinic $clinic;
    private User $doctor;
    private User $staff;
    private Patient $patient;
    private Medication $amox;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->clinic = $this->clinic('Sampaguita Clinic');
        $this->doctor = $this->user('Ramon Doctor', roles: [$this->clinic->id => 'DOCTOR']);
        $this->staff = $this->user('Joy Staff', roles: [$this->clinic->id => 'STAFF']);
        Doctor::create(['user_id' => $this->doctor->id, 'display_name' => 'Ramon Doctor, MD', 'prc_license_no' => '0123456',
                        'license_verified_at' => now()]);
        $this->patient = Patient::create(['clinic_id' => $this->clinic->id, 'last_name' => 'Dela Cruz', 'first_name' => 'Juan']);
        $this->amox = Medication::create(['generic_name' => 'Amoxicillin', 'strength' => '500 mg', 'dosage_form' => 'capsule']);
        $this->admin->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
    }

    private function url(string $path = ''): string
    {
        return '/admin/'.$this->clinic->code.$path;
    }

    private function inPanel(User $user): void
    {
        $this->actingAs($user);
        $this->as($user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($this->clinic);
        Filament::bootCurrentPanel();
    }

    public function test_everyone_in_the_clinic_opens_patients_medicines_and_prescriptions(): void
    {
        foreach ([$this->admin, $this->doctor, $this->staff] as $user) {
            $this->flushSession();                       // a new sign-in (the session remembers the last person)
            $this->actingAs($user);
            foreach (['/patients', '/patients/create', "/patients/{$this->patient->id}/edit", '/medications',
                      '/prescriptions', '/prescriptions/create'] as $path) {
                $this->get($this->url($path))->assertOk();
            }
        }
        $this->as($this->admin);                         // requests clear the database user
        $other = $this->user('Other Staff', roles: [$this->clinic('Other')->id => 'STAFF']);
        $this->flushSession();
        $this->actingAs($other);
        $this->get($this->url('/patients'))->assertNotFound();
    }

    public function test_staff_register_a_patient(): void
    {
        $this->inPanel($this->staff);
        Livewire::test(CreatePatient::class)
            ->fillForm(['last_name' => 'Santos', 'first_name' => 'Maria', 'sex' => 'F', 'birth_date' => '1990-02-03'])
            ->call('create')->assertHasNoFormErrors();

        $this->as($this->admin);
        $this->assertSame('P-000002', Patient::where('last_name', 'Santos')->value('patient_number'));
    }

    public function test_a_clinic_admin_adds_a_clinic_medicine(): void
    {
        $this->inPanel($this->user('Liza Admin', roles: [$this->clinic->id => 'CLINIC_ADMIN']));
        Livewire::test(ManageMedications::class)
            ->callAction('create', ['generic_name' => 'Lagundi', 'strength' => '600 mg', 'dosage_form' => 'tablet'])
            ->assertHasNoActionErrors();

        $this->as($this->admin);
        $this->assertSame($this->clinic->id, Medication::where('generic_name', 'Lagundi')->value('clinic_id'));
    }

    public function test_staff_prepare_a_draft_and_the_doctor_issues_it(): void
    {
        $this->inPanel($this->staff);
        Livewire::test(CreatePrescription::class)
            ->fillForm(['patient_id' => $this->patient->id, 'doctor_user_id' => $this->doctor->id, 'follow_up' => 'In 1 week'])
            ->set('data.items', ['a' => ['medication_id' => $this->amox->id, 'generic_name' => 'Amoxicillin',
                                         'strength' => '500 mg', 'dosage_form' => 'capsule', 'sig' => '1 capsule 3 times a day',
                                         'quantity' => '#21', 'duration' => '7 days', 'is_dangerous_drug' => false]])
            ->call('create')->assertHasNoFormErrors();

        $this->as($this->admin);
        $p = Prescription::where('patient_id', $this->patient->id)->firstOrFail();
        $this->assertSame('DRAFT', $p->status);
        $this->assertSame(['Amoxicillin'], $p->items()->pluck('generic_name')->all());

        // staff don't see Issue; the doctor does
        $this->inPanel($this->staff);
        Livewire::test(ViewPrescription::class, ['record' => $p->id])->assertActionHidden('issue');

        $this->inPanel($this->doctor);
        Livewire::test(ViewPrescription::class, ['record' => $p->id])->callAction('issue')->assertNotified();

        $this->as($this->admin);
        $p->refresh();
        $this->assertSame('ISSUED', $p->status);
        $this->assertNotNull($p->documents()->first(), 'the PDF was made');

        $this->inPanel($this->doctor);
        Livewire::test(ViewPrescription::class, ['record' => $p->id])
            ->assertActionVisible('pdf')->assertActionHidden('edit')
            ->callAction('cancel', ['reason' => 'Wrong strength']);
        $this->as($this->admin);
        $this->assertSame('CANCELLED', $p->fresh()->status);
    }
}
