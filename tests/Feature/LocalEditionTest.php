<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use App\Services\Prescribing;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\DatabaseTestCase;

// The local edition (Windows install): first-run screen, no licence verification, optional two-factor sign-in.
// The edition switch (scribe.settings) is written by the table owner outside the test's transaction, so each test
// that turns it on turns it off again.
class LocalEditionTest extends DatabaseTestCase
{
    private function localEdition(bool $on): void
    {
        config(['scribe.local' => $on]);
        $settings = DB::connection('pgsql_owner')->table('scribe.settings');
        $on ? $settings->upsert(['key' => 'local_edition', 'value' => 'true'], ['key'], ['value'])
            : $settings->where('key', 'local_edition')->delete();
    }

    protected function tearDown(): void
    {
        $this->localEdition(false);
        parent::tearDown();
    }

    // A fresh install: nobody has an account yet
    private function noAccounts(): void
    {
        User::query()->delete();
        Auth::logout();
        $this->as(null);
    }

    private function firstRun(array $overrides = [])
    {
        return $this->post('/setup', $overrides + [
            'clinic_name' => 'Sampaguita Family Clinic', 'clinic_address' => '12 Mabini St', 'name' => 'Juan Dela Cruz, MD',
            'specialty' => 'Family Medicine', 'prc_license_no' => '0123456', 'email' => 'Dr.Juan@Example.test',
            'password' => 'a-long-password-1', 'password_confirmation' => 'a-long-password-1',
        ]);
    }

    public function test_only_the_local_edition_issues_without_a_verified_licence(): void
    {
        Storage::fake('local');
        $clinic = $this->clinic('Solo Clinic');
        $doc = $this->user('Solo Doc', roles: [$clinic->id => 'DOCTOR']);
        Doctor::create(['user_id' => $doc->id, 'display_name' => 'Solo Doc, MD', 'prc_license_no' => '1234567']);
        $patient = Patient::create(['clinic_id' => $clinic->id, 'last_name' => 'Cruz', 'first_name' => 'Ana']);
        $draft = function () use ($clinic, $doc, $patient) {
            $p = Prescription::create(['clinic_id' => $clinic->id, 'patient_id' => $patient->id, 'doctor_user_id' => $doc->id]);
            $p->items()->create(['clinic_id' => $clinic->id, 'generic_name' => 'Paracetamol', 'sig' => '1 tablet']);

            return $p;
        };

        $this->as($doc);
        $this->assertSame("The doctor's PRC licence has not been verified by an administrator",
                          app(Prescribing::class)->issue($draft()), 'online: verification needed');

        $this->localEdition(true);
        $this->assertNull(app(Prescribing::class)->issue($draft()), 'local: no verification');

        $this->as($this->admin);
        Doctor::where('user_id', $doc->id)->update(['prc_valid_until' => now()->subDay()->toDateString()]);
        $this->as($doc);
        $this->assertSame("The doctor's PRC licence has expired", app(Prescribing::class)->issue($draft()),
                          'an expired licence still stops it');
    }

    public function test_the_first_run_screen_makes_the_doctor_their_clinic_and_licence_then_closes(): void
    {
        $this->noAccounts();
        $this->get('/setup')->assertNotFound();                  // online: there is no setup screen
        $this->get('/admin/login')->assertOk();

        $this->localEdition(true);
        $this->get('/admin/login')->assertRedirect('/setup');
        $this->get('/setup')->assertOk()->assertSee('Welcome to Scribe');
        $this->firstRun()->assertRedirect('/admin');
        $this->assertAuthenticated();

        $user = User::where('email', 'dr.juan@example.test')->firstOrFail();
        $this->assertTrue($user->is_admin);
        $this->as($user);
        $clinic = Clinic::where('code', 'sampaguita')->firstOrFail();
        $this->assertSame('12 Mabini St', $clinic->address);
        $this->assertSame('DOCTOR', DB::table('clinic_user')->where('user_id', $user->id)->value('role'));
        $doctor = Doctor::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(['Juan Dela Cruz, MD', '0123456', 'Family Medicine'],
                          [$doctor->display_name, $doctor->prc_license_no, $doctor->specialty]);

        // once there is an account the screen is gone, and sign-in is the normal one
        Auth::logout();
        $this->get('/setup')->assertNotFound();
        $this->firstRun(['email' => 'second@example.test'])->assertNotFound();
        $this->get('/admin/login')->assertOk();
    }

    public function test_the_database_refuses_a_second_first_run_and_any_online(): void
    {
        $call = fn () => DB::transaction(fn () => DB::select('SELECT scribe.first_run_setup(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            ['X', 'x', null, null, 'Dr X', null, '1', null, 'x@example.test', 'hash']));
        foreach (['local, accounts exist' => true, 'online, no accounts' => false] as $case => $local) {
            if (! $local) {
                $this->noAccounts();
            }
            $this->localEdition($local);
            try {
                $call();
                $this->fail("first_run_setup should refuse: {$case}");
            } catch (QueryException $e) {
                $this->assertStringContainsString('already set up', $e->getMessage(), $case);
            }
        }
    }

    public function test_the_first_run_screen_checks_what_it_is_given(): void
    {
        $this->noAccounts();
        $this->localEdition(true);
        $this->firstRun(['clinic_name' => '', 'email' => 'not-an-email', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors(['clinic_name', 'email', 'password']);
        $this->assertFalse(User::exists());
    }

    public function test_the_clinic_code_is_one_short_word(): void
    {
        $this->assertSame('sampaguita', \App\Http\Controllers\SetupController::code('Sampaguita Family Clinic'));
        $this->assertSame('lukes', \App\Http\Controllers\SetupController::code("Dr. St. Luke's Clinic"));
        $this->assertSame('pangasinanme', \App\Http\Controllers\SetupController::code('Pangasinanmedical Center'));
        $this->assertSame('clinic', \App\Http\Controllers\SetupController::code('Dr. Go'));
    }

    public function test_two_factor_sign_in_is_optional_for_the_local_administrator(): void
    {
        $panel = \Filament\Facades\Filament::getPanel('admin');
        $this->actingAs($this->admin);
        $this->assertTrue($panel->isMultiFactorAuthenticationRequired(), 'online: required for administrators');

        config(['scribe.local' => true]);
        $this->assertFalse($panel->isMultiFactorAuthenticationRequired(), 'local: optional');
    }
}
