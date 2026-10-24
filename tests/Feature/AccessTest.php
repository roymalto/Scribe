<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\ClinicMember;
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

// Row-level security and the audit trail: clinics can't see each other, roles decide who may change what, and only
// an administrator verifies a doctor's licence.
class AccessTest extends DatabaseTestCase
{
    private Clinic $a;
    private Clinic $b;
    private User $docA;
    private User $docB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->clinic('Clinic A');
        $this->b = $this->clinic('Clinic B');
        $this->docA = $this->user('Doc A', roles: [$this->a->id => 'DOCTOR']);
        $this->docB = $this->user('Doc B', roles: [$this->b->id => 'DOCTOR']);
        Doctor::create(['user_id' => $this->docA->id, 'display_name' => 'Doc A, MD', 'prc_license_no' => '1111111']);
        Doctor::create(['user_id' => $this->docB->id, 'display_name' => 'Doc B, MD', 'prc_license_no' => '2222222']);
    }

    private function refused(callable $fn, string $message = 'row-level security'): void
    {
        try {
            DB::transaction($fn);                                // a savepoint, so the test carries on
            $this->fail('the database should have refused this');
        } catch (QueryException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_staff_see_only_their_clinics_and_colleagues(): void
    {
        $this->as($this->user('Sue Staff', roles: [$this->a->id => 'STAFF']));

        $this->assertSame([$this->a->id], Clinic::pluck('id')->all());
        $this->assertSame([$this->a->id], ClinicMember::distinct()->pluck('clinic_id')->all());
        $this->assertSame([$this->docA->id], Doctor::pluck('user_id')->all(), "only this clinic's doctors");
    }

    public function test_a_doctor_in_two_clinics_sees_both_and_colleagues_in_each(): void
    {
        DB::table('clinic_user')->insert(['clinic_id' => $this->b->id, 'user_id' => $this->docA->id, 'role' => 'DOCTOR']);
        $this->as($this->docA);

        $this->assertEqualsCanonicalizing([$this->a->id, $this->b->id], Clinic::pluck('id')->all());
        $this->assertEqualsCanonicalizing([$this->docA->id, $this->docB->id], Doctor::pluck('user_id')->all());
    }

    public function test_only_clinic_admins_change_the_clinic_and_its_staff(): void
    {
        $this->as($this->user('Sue Staff', roles: [$this->a->id => 'STAFF']));
        $this->assertSame(0, Clinic::whereKey($this->a->id)->update(['phone' => '123']));
        $this->refused(fn () => ClinicMember::create(['clinic_id' => $this->a->id, 'user_id' => $this->docB->id, 'role' => 'STAFF']));

        $this->as($this->user('Ann Admin', roles: [$this->a->id => 'CLINIC_ADMIN']));
        $this->assertSame(1, Clinic::whereKey($this->a->id)->update(['phone' => '123']));
        $this->assertSame(0, DB::table('clinics')->where('id', $this->b->id)->update(['phone' => '123']), 'not another clinic');
        ClinicMember::create(['clinic_id' => $this->a->id, 'user_id' => $this->docB->id, 'role' => 'DOCTOR']);
        $this->refused(fn () => ClinicMember::create(['clinic_id' => $this->b->id, 'user_id' => $this->docA->id, 'role' => 'DOCTOR']));
        $this->refused(fn () => Clinic::create(['code' => 'new'.uniqid(), 'name' => 'Not allowed']));
    }

    public function test_doctors_keep_their_own_details_but_cannot_verify_them(): void
    {
        $this->as($this->docA);
        $this->assertSame(1, Doctor::where('user_id', $this->docA->id)->update(['specialty' => 'Cardiology']));
        $this->assertSame(0, DB::table('doctors')->where('user_id', $this->docB->id)->update(['specialty' => 'X']));
        $this->refused(fn () => Doctor::where('user_id', $this->docA->id)->update(['license_verified_at' => now()]),
            'only an administrator can verify a licence');

        // a clinic admin can enter a doctor's details, but not verify them either
        $this->as($this->user('Ann Admin', roles: [$this->a->id => 'CLINIC_ADMIN']));
        $this->assertSame(1, Doctor::where('user_id', $this->docA->id)->update(['ptr_no' => 'PTR-1']));
        $this->refused(fn () => Doctor::where('user_id', $this->docA->id)->update(['license_verified_at' => now()]),
            'only an administrator can verify a licence');
    }

    public function test_an_administrator_verifies_and_changing_the_licence_number_clears_it(): void
    {
        $doctor = Doctor::where('user_id', $this->docA->id)->first();
        $doctor->forceFill(['license_verified_at' => now()])->save();
        $doctor->refresh();
        $this->assertTrue($doctor->isVerified());
        $this->assertSame($this->admin->id, $doctor->license_verified_by, 'recorded as the administrator who verified');

        $this->as($this->docA);
        $doctor->update(['prc_license_no' => '9999999']);
        $this->assertFalse($doctor->fresh()->isVerified());
        $this->assertNull($doctor->fresh()->license_verified_by);

        // an administrator correcting a number keeps (or sets) the verification deliberately
        $this->as($this->admin);
        $doctor->refresh()->forceFill(['prc_license_no' => '9999998', 'license_verified_at' => now()])->save();
        $this->assertTrue($doctor->fresh()->isVerified());
    }

    public function test_changes_are_audited_with_who_did_them_and_the_chain_holds(): void
    {
        $this->as($this->docA);
        Doctor::where('user_id', $this->docA->id)->first()->update(['specialty' => 'Pulmonology']);

        $entry = DB::table('audit_log')->where('table_name', 'doctors')->where('action', 'UPDATE')->orderByDesc('id')->first();
        $this->assertSame($this->docA->id, (int) $entry->user_id);
        $this->assertSame('Pulmonology', json_decode($entry->new_values, true)['specialty']);
        $this->assertNull(DB::selectOne('SELECT scribe.verify_audit_chain() AS broken')->broken);

        $clinicEntry = DB::table('audit_log')->where('table_name', 'clinic_user')->orderByDesc('id')->first();
        $this->assertNotNull($clinicEntry->clinic_id, 'memberships are logged against their clinic');

        try {
            DB::transaction(fn () => DB::table('audit_log')->where('id', $entry->id)->update(['actor' => 'me']));
            $this->fail('the audit log was changed');
        } catch (QueryException $e) {
            $this->assertStringContainsString('permission denied', $e->getMessage());
        }
    }

    public function test_passwords_and_two_factor_secrets_never_reach_the_audit_log(): void
    {
        $this->user('New Person');
        $entry = DB::table('audit_log')->where('table_name', 'users')->orderByDesc('id')->first();
        $values = json_decode($entry->new_values, true);
        $this->assertArrayNotHasKey('password', $values);
        $this->assertArrayNotHasKey('app_authentication_secret', $values);
    }

    public function test_an_inactive_account_sees_nothing(): void
    {
        $sue = $this->user('Sue Staff', roles: [$this->a->id => 'STAFF']);
        $sue->update(['active' => false]);
        $this->as($sue);

        $this->assertSame(0, Clinic::count());
        $this->assertSame(0, Doctor::count());
    }
}
