# Scribe - notes for AI assistants

- Read `README.md` and `docs/design.md` first.
- Everything runs in Docker. On pepito, don't run it locally: `~/bin/devsync scribe <command>` runs it on karokrok
  (dev at http://100.75.80.88:8099, compose project `scribe-dev`).
- Migrations and seeders: always `--database=pgsql_owner`. The app's role is not the owner, so row-level security
  always applies to it. `migrate:fresh` doesn't drop the `scribe` schema: `DROP SCHEMA scribe CASCADE` first.
- Every clinical table needs: a `clinic_id` (or a parent that has one), RLS policies in a migration, the audit
  trigger (migration 000200's list), and tests in `tests/Feature/AccessTest.php` for who can see and change it.
- Filament 5 injects closure arguments **by name** (`$state`, `$record`, `$query`, `$get`, `$set`, `$data`,
  `$operation`): other names get null or fail.
- Date-only casts must be `date:Y-m-d`, or the date picker shows them a day early.
- Tests: `php artisan test` on `scribe_test` only. Each test runs in a rolled-back transaction as an administrator
  unless it calls `$this->as($user)`. After an HTTP request the middleware clears the database user.
- Demo and test data are fictional only. Never real patients or real doctors' licence numbers.
