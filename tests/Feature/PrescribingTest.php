<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\PrescriptionDocument;
use App\Models\User;
use App\Services\Prescribing;
use App\Services\PrescriptionPdf;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\DatabaseTestCase;

// Phase 3 rules, enforced by the database: who issues, what locks, cancelling, correcting, repeating, the printed
// snapshot, and the PDF only clinic members can download.
class PrescribingTest extends DatabaseTestCase
{
    private Clinic $a;
    private Clinic $b;
    private User $doc;
    private User $doc2;
    private User $staff;
    private User $clinicAdmin;
    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->a = $this->clinic('Sampaguita Clinic');
        $this->a->update(['code' => 'samp'.substr(uniqid(), -4), 'address' => '12 Mabini St', 'city' => 'Quezon City']);
        $this->b = $this->clinic('Other Clinic');
        $this->doc = $this->user('Ramon Doc', roles: [$this->a->id => 'DOCTOR']);
        $this->doc2 = $this->user('Celia Doc', roles: [$this->a->id => 'DOCTOR']);
        $this->staff = $this->user('Joy Staff', roles: [$this->a->id => 'STAFF']);
        $this->clinicAdmin = $this->user('Liza Admin', roles: [$this->a->id => 'CLINIC_ADMIN']);
        Doctor::create(['user_id' => $this->doc->id, 'display_name' => 'Ramon Doc, MD', 'prc_license_no' => '0123456',
                        'prc_valid_until' => now()->addYear()->toDateString(), 'ptr_no' => 'PTR-1',
                        'license_verified_at' => now()]);
        Doctor::create(['user_id' => $this->doc2->id, 'display_name' => 'Celia Doc, MD', 'prc_license_no' => '0654321']);
        $this->patient = Patient::create(['clinic_id' => $this->a->id, 'last_name' => 'Dela Cruz', 'first_name' => 'Juan',
                                          'birth_date' => '1980-05-01', 'sex' => 'M']);
    }

    private function draft(?User $doctor = null, array $items = [['Amoxicillin', false]]): Prescription
    {
        $p = Prescription::create(['clinic_id' => $this->a->id, 'patient_id' => $this->patient->id,
                                   'doctor_user_id' => ($doctor ?? $this->doc)->id, 'follow_up' => 'Return in 1 week']);
        foreach ($items as $n => [$generic, $dangerous]) {
            $p->items()->create(['clinic_id' => $this->a->id, 'sort' => $n, 'generic_name' => $generic, 'strength' => '500 mg',
                                 'dosage_form' => 'capsule', 'quantity' => '#21', 'sig' => '1 capsule 3 times a day',
                                 'duration' => '7 days', 'is_dangerous_drug' => $dangerous]);
        }

        return $p;
    }

    private function issue(Prescription $p): ?string
    {
        return app(Prescribing::class)->issue($p);
    }

    private function refused(callable $fn, string $message): void
    {
        try {
            DB::transaction($fn);
            $this->fail('the database should have refused this');
        } catch (QueryException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_staff_prepare_a_draft_and_only_its_verified_doctor_issues_it(): void
    {
        $this->as($this->staff);
        $p = $this->draft();
        $this->assertSame($this->staff->id, $p->fresh()->created_by);
        $this->assertSame('Only the prescribing doctor can issue this prescription', $this->issue($p));

        $this->as($this->doc2);
        $this->assertSame('Only the prescribing doctor can issue this prescription', $this->issue($p), 'not another doctor');

        $this->as($this->admin);
        $this->assertNotNull($this->issue($p), 'administrators do not issue either');

        $this->as($this->doc);
        $this->assertNull($this->issue($p));
        $p->refresh();
        $this->assertSame('ISSUED', $p->status);
        $this->assertMatchesRegularExpression('/^'.strtoupper($this->a->code).'-\d{4}-00001$/', $p->reference);
        $this->assertNotNull($p->issued_at);
    }

    public function test_issuing_needs_a_verified_unexpired_licence_and_a_medicine(): void
    {
        $this->as($this->doc2);
        $this->assertSame("The doctor's PRC licence has not been verified by an administrator", $this->issue($this->draft($this->doc2)));

        $this->as($this->admin);
        Doctor::where('user_id', $this->doc->id)->update(['prc_valid_until' => now()->subDay()->toDateString()]);
        $this->as($this->doc);
        $this->assertSame("The doctor's PRC licence has expired", $this->issue($this->draft()));

        $this->as($this->admin);
        Doctor::where('user_id', $this->doc->id)->update(['prc_valid_until' => now()->addYear()->toDateString()]);
        $this->as($this->doc);
        $this->assertSame('Add at least one medicine before issuing', $this->issue($this->draft(items: [])));

        // a doctor who left the clinic (or works there in another role) can't issue for it
        $this->as($this->clinicAdmin);
        DB::table('clinic_user')->where('clinic_id', $this->a->id)->where('user_id', $this->doc->id)->update(['role' => 'STAFF']);
        $this->as($this->doc);
        $this->assertSame('Only a doctor of this clinic can issue prescriptions', $this->issue($this->draft()));
    }

    public function test_a_dangerous_drug_needs_the_doctors_s2_number(): void
    {
        $this->as($this->admin);
        $diazepam = Medication::create(['generic_name' => 'Diazepam', 'strength' => '5 mg', 'is_dangerous_drug' => true]);

        $this->as($this->doc);
        $p = $this->draft(items: []);
        // flagged from the catalogue even if the form said otherwise
        $item = $p->items()->create(['clinic_id' => $this->a->id, 'medication_id' => $diazepam->id, 'generic_name' => 'Diazepam',
                                     'sig' => '1 tablet at night', 'is_dangerous_drug' => false]);
        $this->assertTrue($item->fresh()->is_dangerous_drug);
        $this->assertSame("A dangerous drug needs the doctor's S2 number on their licence details", $this->issue($p));

        Doctor::where('user_id', $this->doc->id)->update(['s2_no' => 'S2-12345']);
        $this->assertNull($this->issue($p->fresh()));
        $this->assertSame('S2-12345', $p->fresh()->snapshot['doctor']['s2_no']);
    }

    public function test_an_issued_prescription_is_locked(): void
    {
        $this->as($this->doc);
        $p = $this->draft();
        $this->issue($p);
        $p->refresh();

        $this->refused(fn () => Prescription::whereKey($p->id)->update(['notes' => 'changed']), 'an issued prescription cannot be changed');
        $this->refused(fn () => Prescription::whereKey($p->id)->update(['status' => 'DRAFT']), 'an issued prescription cannot be changed');
        $this->refused(fn () => $p->items()->first()->update(['sig' => '2 capsules']), 'cannot be changed');
        $this->refused(fn () => $p->items()->create(['clinic_id' => $this->a->id, 'generic_name' => 'X', 'sig' => 'y']), 'cannot be changed');
        $this->refused(fn () => $p->items()->delete(), 'cannot be changed');
        $this->refused(fn () => Prescription::whereKey($p->id)->delete(), 'an issued prescription cannot be deleted');
        $this->refused(fn () => Prescription::whereKey($p->id)->update(['status' => 'SUPERSEDED']), 'superseded only by issuing its correction');

        // the snapshot is built by the database and can't be supplied by the app
        $this->refused(fn () => DB::table('prescriptions')->insert(['clinic_id' => $this->a->id, 'patient_id' => $this->patient->id,
            'doctor_user_id' => $this->doc->id, 'status' => 'ISSUED']), 'a prescription starts as a draft');

        // a draft can still change and be deleted
        $d = $this->draft();
        $d->items()->first()->update(['sig' => '1 capsule twice a day']);
        $this->assertSame(1, Prescription::whereKey($d->id)->delete());
    }

    public function test_the_snapshot_keeps_what_was_printed(): void
    {
        $this->as($this->doc);
        $p = $this->draft(items: [['Amoxicillin', false], ['Paracetamol', false]]);
        $this->issue($p);
        $s = $p->fresh()->snapshot;

        $this->assertSame('Sampaguita Clinic', $s['clinic']['name']);
        $this->assertSame('Ramon Doc, MD', $s['doctor']['name']);
        $this->assertSame('0123456', $s['doctor']['prc_license_no']);
        $this->assertSame('Dela Cruz', $s['patient']['last_name']);
        $this->assertSame(\Carbon\Carbon::parse('1980-05-01')->age, $s['patient']['age']);
        $this->assertSame(['Amoxicillin', 'Paracetamol'], array_column($s['items'], 'generic_name'));
        $this->assertSame('Return in 1 week', $s['follow_up']);

        // later changes to the clinic or doctor don't alter an issued prescription
        $this->as($this->admin);
        $this->a->update(['name' => 'Renamed Clinic']);
        Doctor::where('user_id', $this->doc->id)->update(['display_name' => 'Someone Else']);
        $this->assertSame('Sampaguita Clinic', $p->fresh()->snapshot['clinic']['name']);
        $this->assertSame('Ramon Doc, MD', $p->fresh()->snapshot['doctor']['name']);
    }

    public function test_cancelling_needs_a_reason_and_the_doctor_or_a_clinic_admin(): void
    {
        $this->as($this->doc);
        $p = $this->draft();
        $this->issue($p);
        $svc = app(Prescribing::class);

        $this->as($this->staff);
        $this->assertSame('Only the prescribing doctor or a clinic admin can cancel a prescription', $svc->cancel($p->fresh(), 'wrong'));

        $this->as($this->doc);
        $this->assertStringContainsString('prescriptions_cancel_reason_ck', (string) $svc->cancel($p->fresh(), '  '));

        $this->as($this->clinicAdmin);
        $this->assertNull($svc->cancel($p->fresh(), 'Wrong patient'));
        $p->refresh();
        $this->assertSame('CANCELLED', $p->status);
        $this->assertSame($this->clinicAdmin->id, $p->cancelled_by);
        $this->refused(fn () => Prescription::whereKey($p->id)->update(['status' => 'ISSUED']), 'an issued prescription cannot be changed');
    }

    public function test_a_correction_supersedes_the_original_when_issued(): void
    {
        $this->as($this->doc);
        $original = $this->draft();
        $this->issue($original);
        $svc = app(Prescribing::class);

        $fix = $svc->copyAsDraft($original->fresh(), corrects: true);
        $this->assertSame('DRAFT', $fix->status);
        $this->assertSame(1, $fix->items()->count());
        $fix->items()->first()->update(['strength' => '250 mg']);
        $this->assertSame('ISSUED', $original->fresh()->status, 'still issued until the correction is');

        $this->assertNull($this->issue($fix->fresh()));
        $this->assertSame('SUPERSEDED', $original->fresh()->status);
        $this->assertSame($original->reference, $fix->fresh()->snapshot['supersedes']);

        // a second correction of the same original can no longer be issued
        $this->refused(fn () => $svc->copyAsDraft($original->fresh(), corrects: true), 'only an issued prescription of the same patient');
    }

    public function test_a_repeat_is_a_new_draft_from_the_history(): void
    {
        $this->as($this->doc);
        $first = $this->draft(items: [['Losartan', false], ['Amlodipine', false]]);
        $this->issue($first);

        $this->as($this->staff);
        $repeat = app(Prescribing::class)->copyAsDraft($first->fresh(), corrects: false);
        $this->assertSame($first->id, $repeat->repeat_of_id);
        $this->assertSame(['Losartan', 'Amlodipine'], $repeat->items()->pluck('generic_name')->all());
        $this->assertSame('ISSUED', $first->fresh()->status, 'repeating does not touch the original');

        $this->as($this->doc);
        $this->assertNull($this->issue($repeat->fresh()));
        $this->assertStringEndsWith('-00002', $repeat->fresh()->reference);
    }

    public function test_other_clinics_never_see_prescriptions(): void
    {
        $this->as($this->doc);
        $p = $this->draft();
        $this->issue($p);

        $this->as($this->user('Other Staff', roles: [$this->b->id => 'STAFF']));
        $this->assertSame(0, Prescription::count());
        $this->assertSame(0, DB::table('prescription_items')->count());
        $this->assertSame(0, PrescriptionDocument::count());
    }

    public function test_the_pdf_is_stored_privately_with_its_hash_and_only_members_download_it(): void
    {
        $this->as($this->doc);
        $p = $this->draft();
        $this->issue($p);
        $p->refresh();

        $doc = $p->documents()->first();
        $this->assertNotNull($doc, 'made when issued');
        $bytes = Storage::disk('local')->get($doc->path);
        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertSame(hash('sha256', $bytes), $doc->sha256);
        $this->assertSame(0, PrescriptionDocument::whereKey($doc->id)->update(['sha256' => str_repeat('0', 64)]), 'never changed');
        $this->assertSame(0, PrescriptionDocument::whereKey($doc->id)->delete(), 'never removed');

        \Illuminate\Support\Facades\Auth::guard()->forgetUser();
        $this->get("/prescriptions/{$p->id}/pdf")->assertRedirect('/admin/login');
        $this->actingAs($this->staff)->get("/prescriptions/{$p->id}/pdf")->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->user('Other Staff', roles: [$this->b->id => 'STAFF']))
            ->get("/prescriptions/{$p->id}/pdf")->assertNotFound();

        // a tampered file is refused, not served
        Storage::disk('local')->put($doc->path, 'not the original');
        $this->expectException(\RuntimeException::class);
        $this->as($this->doc);
        app(PrescriptionPdf::class)->current($p);
    }

    public function test_a_draft_has_no_pdf(): void
    {
        $this->as($this->doc);
        $p = $this->draft();
        $this->actingAs($this->doc)->get("/prescriptions/{$p->id}/pdf")->assertNotFound();
        $this->as($this->doc);
        $this->refused(fn () => PrescriptionDocument::create(['clinic_id' => $this->a->id, 'prescription_id' => $p->id,
            'path' => 'x', 'sha256' => str_repeat('a', 64), 'generated_at' => now()]), 'row-level security');
    }

    public function test_prescriptions_are_audited(): void
    {
        $this->as($this->doc);
        $p = $this->draft();
        $this->issue($p);

        $this->as($this->admin);
        $entry = DB::table('audit_log')->where('table_name', 'prescriptions')->where('action', 'UPDATE')->orderByDesc('id')->first();
        $this->assertSame($this->a->id, (int) $entry->clinic_id);
        $this->assertSame($this->doc->id, (int) $entry->user_id);
        $this->assertSame('ISSUED', json_decode($entry->new_values, true)['status']);
        $this->assertNull(DB::selectOne('SELECT scribe.verify_audit_chain() AS broken')->broken);
    }
}
