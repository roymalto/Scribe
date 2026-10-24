# Scribe

Electronic prescriptions for clinics and doctors in the Philippines: clinics, doctors' PRC details, patients,
prescriptions and branded PDF prescriptions. Laravel 13, Filament 5, PostgreSQL 17 with row-level security per
clinic and a hash-chained audit trail. Design and phases: `docs/design.md`.

## Development

```sh
cp .env.example .env          # set every password; SCRIBE_ADMIN_* creates the first administrator
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose up -d --force-recreate app        # the container reads .env at start
docker compose exec app php artisan filament:assets
docker compose exec app php artisan migrate --database=pgsql_owner --seed --force
docker compose exec app php artisan scribe:demo  # fictional clinics, needs SCRIBE_DEMO_PASSWORD
docker compose exec app php artisan test         # uses scribe_test only
```

Open http://localhost:8099/admin. Administrators must set up two-factor sign-in (an authenticator app) at their
first sign-in.

## Code map

- `database/migrations/2026_10_24_000100_*`: tables; 000200 audit trail; 000300 row-level security.
- `app/Support/DatabaseContext.php`, `app/Http/Middleware/SetDatabaseUser.php`: tell PostgreSQL who is signed in.
- `app/Filament/`: screens. Clinic: Staff, Doctors, Clinic details (tenant menu). Platform: Clinics, Users.
- `tests/Feature/`: access rules and screens. `tests/browser/smoke.py`: browser check (Playwright).

Demo and test data are fictional only. Never real patients.
