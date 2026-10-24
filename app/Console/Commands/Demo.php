<?php

namespace App\Console\Commands;

use App\Models\Clinic;
use App\Models\ClinicMember;
use App\Models\Doctor;
use App\Models\User;
use App\Support\DatabaseContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// Two fictional clinics with staff, for trying Scribe out. All names are made up. Accounts use SCRIBE_DEMO_PASSWORD.
class Demo extends Command
{
    protected $signature = 'scribe:demo';
    protected $description = 'Load two fictional demo clinics and their staff (add-only)';

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
        }), actor: 'scribe:demo');

        $this->info('Demo clinics loaded: sampaguita, narra. Sign in as '.implode(', ', array_keys(self::PEOPLE)).' (SCRIBE_DEMO_PASSWORD).');

        return self::SUCCESS;
    }
}
