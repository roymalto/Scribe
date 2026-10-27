<?php

namespace App\Console\Commands;

use App\Models\Clinic;
use App\Models\ClinicMember;
use App\Models\Doctor;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\PatientAllergy;
use App\Models\Prescription;
use App\Models\User;
use App\Services\Prescribing;
use App\Support\DatabaseContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// Two fictional clinics with staff, patients and prescriptions, for trying Scribe out. All names are made up. Accounts use SCRIBE_DEMO_PASSWORD.
class Demo extends Command
{
    protected $signature = 'scribe:demo';
    protected $description = 'Load two fictional demo clinics, their staff, patients and prescriptions (add-only)';

    private const CLINICS = [
        ['code' => 'sampaguita', 'name' => 'Sampaguita Family Clinic', 'address' => '12 Mabini Street, Barangay San Roque',
         'city' => 'Quezon City', 'phone' => '(02) 8123 4567', 'hours' => 'Mon-Sat 9:00-17:00',
         'header_note' => 'Family medicine and internal medicine'],
        ['code' => 'narra', 'name' => 'Narra Medical Center Clinic', 'address' => 'Room 305, Narra Medical Arts Building',
         'city' => 'Makati', 'phone' => '(02) 8765 4321', 'hours' => 'Mon-Fri 8:00-16:00',
         'header_note' => 'Internal medicine'],
    ];

    // email => [name, [clinic code => role], doctor details or null]
    private const PEOPLE = [
        'demo.admin@example.test' => ['Liza Santos', ['sampaguita' => 'CLINIC_ADMIN'], null],
        'demo.doctor@example.test' => ['Dr Ramon Villanueva', ['sampaguita' => 'DOCTOR', 'narra' => 'DOCTOR'],
            ['Ramon P. Villanueva, MD, FPCP', 'Internal Medicine', '0123456', true]],
        'demo.doctor2@example.test' => ['Dr Celia Bautista', ['sampaguita' => 'DOCTOR'],
            ['Celia M. Bautista, MD, DPAFP', 'Family Medicine', '0654321', false]],
        'demo.staff@example.test' => ['Joy Mendoza', ['sampaguita' => 'STAFF'], null],
        'demo.narra@example.test' => ['Paolo Reyes', ['narra' => 'CLINIC_ADMIN'], null],
    ];

    // clinic => [last, first, birth date, sex, [allergy => [severity, reaction]]]
    private const PATIENTS = [
        'sampaguita' => [
            ['Dizon', 'Carmela', '1972-03-14', 'F', []],
            ['Ocampo', 'Benjamin', '1990-11-02', 'M', []],
            ['Villareal', 'Lourdes', '1958-07-21', 'F', ['Sulfa' => ['MODERATE', 'rash']]],
            ['Reyes', 'Marisol', '1985-01-30', 'F', ['Penicillin' => ['SEVERE', 'hives and swelling']]],
            ['Tan', 'Miguel', '2018-06-09', 'M', []],
        ],
        'narra' => [
            ['Lim', 'Antonio', '1966-09-17', 'M', []],
            ['Navarro', 'Teresita', '1979-04-05', 'F', ['Ibuprofen' => ['MILD', 'stomach upset']]],
        ],
    ];

    public function handle(): int
    {
        $password = (string) config('scribe.demo_password');
        if (strlen($password) < 12) {
            $this->error('Set SCRIBE_DEMO_PASSWORD (12+ characters) first.');

            return self::FAILURE;
        }
        $admin = User::where('is_admin', true)->orderBy('id')->first();
        if (! $admin) {
            $this->error('Create the administrator first (php artisan db:seed --database=pgsql_owner).');

            return self::FAILURE;
        }

        DatabaseContext::asUser($admin->id, fn () => DB::transaction(function () use ($password) {
            $clinics = collect(self::CLINICS)->mapWithKeys(fn ($c) => [$c['code'] => Clinic::firstOrCreate(['code' => $c['code']], $c)]);
            foreach (self::PEOPLE as $email => [$name, $roles, $doctor]) {
                $user = User::firstOrCreate(['email' => $email], ['name' => $name, 'password' => $password]);
                foreach ($roles as $code => $role) {
                    ClinicMember::firstOrCreate(['clinic_id' => $clinics[$code]->id, 'user_id' => $user->id], ['role' => $role]);
                }
                if ($doctor && ! Doctor::where('user_id', $user->id)->exists()) {
                    [$display, $specialty, $prc, $verified] = $doctor;
                    Doctor::create(['user_id' => $user->id, 'display_name' => $display, 'specialty' => $specialty,
                                    'prc_license_no' => $prc, 'prc_valid_until' => now()->addYears(2)->toDateString(),
                                    'ptr_no' => 'PTR-'.$prc, 'license_verified_at' => $verified ? now() : null]);
                }
            }
            $this->patients($clinics);
        }), actor: 'scribe:demo');

        // Prescriptions are prepared and issued by Dr Villanueva himself (only the prescribing doctor issues)
        $doctor = User::where('email', 'demo.doctor@example.test')->first();
        DatabaseContext::asUser($doctor->id, fn () => $this->prescriptions(Clinic::where('code', 'sampaguita')->first(), $doctor),
            actor: 'scribe:demo');

        $this->info('Demo clinics loaded: sampaguita, narra. Sign in as '.implode(', ', array_keys(self::PEOPLE)).' (SCRIBE_DEMO_PASSWORD).');

        return self::SUCCESS;
    }

    private function patients($clinics): void
    {
        foreach (self::PATIENTS as $code => $people) {
            foreach ($people as [$last, $first, $born, $sex, $allergies]) {
                $p = Patient::firstOrCreate(['clinic_id' => $clinics[$code]->id, 'last_name' => $last, 'first_name' => $first],
                                            ['birth_date' => $born, 'sex' => $sex, 'address' => 'Fictional demo address']);
                foreach ($allergies as $substance => [$severity, $reaction]) {
                    PatientAllergy::firstOrCreate(['clinic_id' => $p->clinic_id, 'patient_id' => $p->id, 'substance' => $substance],
                                                  ['severity' => $severity, 'reaction' => $reaction]);
                }
            }
        }
    }

    // One issued prescription (with its PDF) and one draft waiting for the doctor to issue
    private function prescriptions(Clinic $clinic, User $doctor): void
    {
        $make = function (string $last, array $items, string $followUp) use ($clinic, $doctor): ?Prescription {
            $patient = Patient::where('clinic_id', $clinic->id)->where('last_name', $last)->first();
            if (! $patient || $patient->prescriptions()->exists()) {
                return null;
            }
            $p = Prescription::create(['clinic_id' => $clinic->id, 'patient_id' => $patient->id,
                                       'doctor_user_id' => $doctor->id, 'follow_up' => $followUp]);
            foreach ($items as $n => [$generic, $strength, $sig, $qty, $days]) {
                $m = Medication::whereNull('clinic_id')->where('generic_name', $generic)->where('strength', $strength)->first();
                $p->items()->create(['clinic_id' => $clinic->id, 'sort' => $n, 'medication_id' => $m?->id,
                    'generic_name' => $generic, 'strength' => $strength, 'dosage_form' => $m?->dosage_form,
                    'sig' => $sig, 'quantity' => $qty, 'duration' => $days]);
            }

            return $p;
        };

        if ($issued = $make('Ocampo', [['Amoxicillin', '500 mg', '1 capsule 3 times a day', '#21', '7 days'],
                                       ['Paracetamol', '500 mg', '1 tablet every 6 hours as needed for fever', '#10', null]],
                            'Return if fever persists after 3 days')) {
            if ($error = app(Prescribing::class)->issue($issued)) {
                $this->warn("Demo prescription not issued: {$error}");
            }
        }
        $make('Dizon', [['Losartan', '50 mg', '1 tablet once a day', '#30', '30 days'],
                        ['Amlodipine', '5 mg', '1 tablet once a day', '#30', '30 days']], 'Return in 1 month with BP log');
    }
}
