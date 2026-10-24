<?php

namespace Tests;

use App\Models\Clinic;
use App\Models\User;
use App\Support\DatabaseContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Tests run on scribe_test only. The schema is rebuilt once per run by the owner role (the app role can't create
 * tables); each test then runs in a transaction on the app connection that is rolled back afterwards.
 */
abstract class DatabaseTestCase extends TestCase
{
    use DatabaseTransactions;

    private static bool $built = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (! self::$built) {
            foreach (['pgsql', 'pgsql_owner'] as $c) {
                $db = config("database.connections.$c.database");
                if (! str_ends_with((string) $db, '_test')) {
                    $this->fail("Refusing to run database tests against '$db' ($c): use a *_test database.");
                }
            }
            $app = config('database.connections.pgsql.username');
            DB::connection('pgsql_owner')->unprepared(
                "DROP SCHEMA IF EXISTS scribe CASCADE; DROP SCHEMA public CASCADE; CREATE SCHEMA public;
                 GRANT USAGE ON SCHEMA public TO \"{$app}\";");
            Artisan::call('migrate', ['--database' => 'pgsql_owner', '--force' => true]);
            self::$built = true;
        }
        // Tests act as an administrator unless they say otherwise (row-level security needs someone)
        $this->admin = $this->user('Test Admin', admin: true);
        $this->as($this->admin);
    }

    protected User $admin;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        // parent::tearDown() rolls the test's transaction back; the context goes with the connection. (Clearing it
        // here first would fail in a transaction a test left aborted on purpose, and skip the rollback.)
        parent::tearDown();
    }

    protected function user(string $name, bool $admin = false, array $roles = []): User
    {
        $u = User::create(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'.'.uniqid().'@example.test',
                           'password' => 'x', 'is_admin' => $admin]);
        if ($roles) {
            $this->as($this->admin);                         // memberships are given by an administrator
            foreach ($roles as $clinicId => $role) {
                DB::table('clinic_user')->insert(['clinic_id' => $clinicId, 'user_id' => $u->id, 'role' => $role]);
            }
        }

        return $u;
    }

    // Act as this person: signed in to the app, and known to the database (row-level security, the audit trail)
    protected function as(?User $user): void
    {
        if ($user) {
            $this->actingAs($user);
        }
        DatabaseContext::set($user?->id);
    }

    protected function clinic(string $name = 'Test Clinic'): Clinic
    {
        return Clinic::create(['code' => 'c'.substr(uniqid(), -8), 'name' => $name]);
    }
}
