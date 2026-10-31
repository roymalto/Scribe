<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

// php artisan db:seed --database=pgsql_owner   (add-only: safe to run on every start)
//   - whether this is the local edition (SCRIBE_EDITION=local, the Windows install), in scribe.settings.
//   - the first administrator, when there are no users yet (SCRIBE_ADMIN_EMAIL / SCRIBE_ADMIN_PASSWORD).
//     They create the first clinic when they sign in. The local edition asks for both on its first-run screen.
//   - the shared medicine catalogue, when it is empty (MedicationSeeder).
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (config('scribe.local')) {
            DB::table('scribe.settings')->upsert(['key' => 'local_edition', 'value' => 'true'], ['key'], ['value']);
        } else {
            DB::table('scribe.settings')->where('key', 'local_edition')->delete();
        }

        if (! User::exists() && ! config('scribe.local')) {
            $email = config('scribe.admin_email');
            $password = (string) config('scribe.admin_password');
            if ($email && strlen($password) >= 12) {
                User::create(['name' => 'Administrator', 'email' => $email, 'password' => $password, 'is_admin' => true]);
                $this->command?->info("Created administrator {$email}.");
            } else {
                $this->command?->warn('Set SCRIBE_ADMIN_EMAIL and SCRIBE_ADMIN_PASSWORD (12+ characters) to create the first administrator.');
            }
        }
        $this->call(MedicationSeeder::class);
    }
}
