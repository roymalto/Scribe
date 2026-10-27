<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\PatientAllergy;
use App\Models\User;
use App\Services\Prescribing;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

// Phase 2 rules: each clinic's patients and allergies stay in that clinic, staff register them, only clinic admins
// delete; the shared medicine catalogue is kept by administrators and each clinic adds its own.
class PatientsTest extends DatabaseTestCase
{
    private Clinic $a;
    private Clinic $b;
    private User $staffA;
    private User $adminA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->clinic('Clinic A');
        $this->b = $this->clinic('Clinic B');
        $this->staffA = $this->user('Sue Staff', roles: [$this->a->id => 'STAFF']);
        $this->adminA = $this->user('Ann Admin', roles: [$this->a->id => 'CLINIC_ADMIN']);
    }

    private function patient(Clinic $clinic, string $last = 'Dela Cruz'): Patient
    {
        return Patient::create(['clinic_id' => $clinic->id, 'last_name' => $last, 'first_name' => 'Juan', 'sex' => 'M',
                                'birth_date' => '1980-05-01']);
    }

    private function refused(callable $fn, string $message = 'row-level security'): void
    {
        try {
            DB::transaction($fn);
            $this->fail('the database should have refused this');
        } catch (QueryException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_patient_numbers_are_given_per_clinic(): void
    {
        $this->assertSame('P-000001', $this->patient($this->a)->fresh()->patient_number);
        $this->assertSame('P-000002', $this->patient($this->a, 'Santos')->fresh()->patient_number);
        $this->assertSame('P-000001', $this->patient($this->b)->fresh()->patient_number, 'each clinic counts on its own');
        $own = Patient::create(['clinic_id' => $this->a->id, 'patient_number' => 'OLD-17', 'last_name' => 'Reyes', 'first_name' => 'Ana']);
        $this->assertSame('OLD-17', $own->fresh()->patient_number, 'a number typed in is kept');
        $this->refused(fn () => Patient::create(['clinic_id' => $this->a->id, 'patient_number' => 'OLD-17',
                                                 'last_name' => 'X', 'first_name' => 'Y']), 'duplicate key');
    }

    public function test_staff_register_and_update_patients_but_only_clinic_admins_delete(): void
    {
        $this->as($this->staffA);
        $p = $this->patient($this->a);
        $this->assertSame(1, Patient::whereKey($p->id)->update(['phone' => '0917 000 0000']));
        PatientAllergy::create(['clinic_id' => $this->a->id, 'patient_id' => $p->id, 'substance' => 'Penicillin']);
        $this->assertSame(0, Patient::whereKey($p->id)->delete(), 'staff cannot delete');
        $this->assertSame(0, PatientAllergy::where('patient_id', $p->id)->delete());

        $this->as($this->adminA);
        $this->assertSame(1, PatientAllergy::where('patient_id', $p->id)->delete());
        $this->assertSame(1, Patient::whereKey($p->id)->delete());
    }

    public function test_no_clinic_sees_or_changes_another_clinics_patients(): void
    {
        $pb = $this->patient($this->b, 'Other');
        PatientAllergy::create(['clinic_id' => $this->b->id, 'patient_id' => $pb->id, 'substance' => 'Sulfa']);

        $this->as($this->staffA);
        $this->assertSame(0, Patient::count());
        $this->assertSame(0, PatientAllergy::count());
        $this->assertSame(0, DB::table('patients')->where('id', $pb->id)->update(['last_name' => 'Hacked']));
        $this->refused(fn () => Patient::create(['clinic_id' => $this->b->id, 'last_name' => 'X', 'first_name' => 'Y']));

        // an allergy can't be attached to another clinic's patient, even by naming this clinic
        $this->refused(fn () => PatientAllergy::create(['clinic_id' => $this->a->id, 'patient_id' => $pb->id,
                                                        'substance' => 'X']), 'foreign key');
    }

    public function test_the_shared_catalogue_is_kept_by_administrators_and_clinics_add_their_own(): void
    {
        $shared = Medication::create(['generic_name' => 'Paracetamol', 'strength' => '500 mg', 'dosage_form' => 'tablet']);
        $own = Medication::create(['clinic_id' => $this->b->id, 'generic_name' => 'Clinic B special']);

        $this->as($this->staffA);
        $this->assertSame([$shared->id], Medication::pluck('id')->all(), 'shared yes, another clinic\'s own no');
        $this->assertSame(0, Medication::whereKey($shared->id)->update(['strength' => '650 mg']));
        $this->refused(fn () => Medication::create(['clinic_id' => $this->a->id, 'generic_name' => 'X']));

        $this->as($this->adminA);
        $mine = Medication::create(['clinic_id' => $this->a->id, 'generic_name' => 'Lagundi', 'dosage_form' => 'syrup']);
        $this->assertEqualsCanonicalizing([$shared->id, $mine->id], Medication::pluck('id')->all());
        $this->assertSame(0, Medication::whereKey($shared->id)->update(['strength' => '650 mg']), 'clinic admins keep only their own');
        $this->refused(fn () => Medication::create(['generic_name' => 'Shared by a clinic admin']));
        $this->assertSame(0, Medication::whereKey($own->id)->delete());
    }

    public function test_patients_and_allergies_are_audited_against_their_clinic(): void
    {
        $this->as($this->staffA);
        $p = $this->patient($this->a);
        PatientAllergy::create(['clinic_id' => $this->a->id, 'patient_id' => $p->id, 'substance' => 'Aspirin']);

        $this->as($this->admin);
        foreach (['patients', 'patient_allergies'] as $table) {
            $entry = DB::table('audit_log')->where('table_name', $table)->orderByDesc('id')->first();
            $this->assertSame($this->a->id, (int) $entry->clinic_id);
            $this->assertSame($this->staffA->id, (int) $entry->user_id);
        }
        $this->assertNull(DB::selectOne('SELECT scribe.verify_audit_chain() AS broken')->broken);
    }

    public function test_allergy_warnings_match_generics_and_classes(): void
    {
        $p = $this->patient($this->a);
        PatientAllergy::create(['clinic_id' => $this->a->id, 'patient_id' => $p->id, 'substance' => 'Penicillin',
                                'severity' => 'SEVERE', 'reaction' => 'hives']);
        PatientAllergy::create(['clinic_id' => $this->a->id, 'patient_id' => $p->id, 'substance' => 'Ibuprofen']);
        PatientAllergy::create(['clinic_id' => $this->a->id, 'patient_id' => $p->id, 'substance' => 'Paracetamol',
                                'status' => 'INACTIVE']);

        $w = Prescribing::allergyWarnings($p, ['Co-amoxiclav', 'Ibuprofen', 'Paracetamol', 'Losartan']);
        $this->assertSame(['Co-amoxiclav: allergy to Penicillin (severe, hives)', 'Ibuprofen: allergy to Ibuprofen'], $w);
    }
}
