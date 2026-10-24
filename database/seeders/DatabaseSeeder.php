<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

// php artisan db:seed --database=pgsql_owner   (add-only: safe to run on every start)
//   - the first administrator, when there are no users yet (SCRIBE_ADMIN_EMAIL / SCRIBE_ADMIN_PASSWORD).
//     They create the first clinic when they sign in.
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! User::exists()) {
            $email = config('scribe.admin_email');
            $password = (string) config('scribe.admin_password');
            if ($email && strlen($password) >= 12) {
                User::create(['name' => 'Administrator', 'email' => $email, 'password' => $password, 'is_admin' => true]);
                $this->command?->info("Created administrator {$email}.");
            } else {
                $this->command?->warn('Set SCRIBE_ADMIN_EMAIL and SCRIBE_ADMIN_PASSWORD (12+ characters) to create the first administrator.');
            }
        }
    }
}
