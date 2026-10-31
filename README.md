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

## Production

`docker-compose.prod.yml` (image target `prod`; data in the volumes `pgdata` and `storage`). Production is ATISCORE
(`~/scribe`, port 8100), deployed from karokrok with `bin/deploy-prod.sh` (ships `main` as a git bundle, writes
`.env` with fresh secrets the first time, builds, waits for `/up`, installs the nightly `bin/backup.sh` at 02:30 into
`~/backups/scribe`, 30 days kept). Invitations need a real mail server: set `MAIL_*` in `.env` there.

Brand files (icon, logo, favicon, app icons): `docs/brand/build_brand.py`, written to `public/brand/`.

## Local edition (one Windows PC)

`sh installer/windows/build.sh` builds `installer/windows/dist/Scribe-Setup-<VERSION>.exe`: the app from the
`scribe:prod` image plus PHP 8.4, PostgreSQL 17 and Caddy for Windows (pinned, checksums checked), installed per user
without administrator rights. `SCRIBE_EDITION=local` turns on a first-run screen (the doctor's account, clinic and
licence; `scribe.first_run_setup()`), makes two-factor sign-in optional and drops licence verification
(`scribe.local_edition()`, migration 001000). The launcher (`installer/windows/launcher/scribe.ps1`) starts everything
on 127.0.0.1 only, updates the database on each start and backs up daily to Documents\Scribe Backups.

## Code map

- `database/migrations/2026_10_24_000100_*`: tables; 000200 audit trail; 000300 row-level security; 000400
  invitations; `2026_10_28_000500` patients, allergies, medicine catalogue; 000600 prescriptions (issue rules, lock,
  snapshot, supersede) and their PDFs.
- `database/seeders/MedicationSeeder.php`: the shared catalogue (about 115 common generics), loaded once.
- `app/Support/DatabaseContext.php`, `app/Http/Middleware/SetDatabaseUser.php`: tell PostgreSQL who is signed in.
- `app/Filament/`: screens. Patients (allergies, medication history), Prescriptions (builder, issue, cancel, correct,
  repeat, PDF), Medicines. Clinic: Staff, Invitations, Doctors, Clinic details (tenant menu). Platform: Clinics, Users.
- `app/Services/Prescribing.php`: issue, cancel, correct/repeat, allergy warnings. `app/Services/PrescriptionPdf.php`,
  `resources/views/pdf/prescription.blade.php`, `PrescriptionPdfController`: the PDF from the issued snapshot,
  stored privately with its SHA-256; members download it at `/prescriptions/{id}/pdf`.
- `app/Services/Invitations.php`, `app/Http/Controllers/InvitationController.php`: invitations and the emailed link.
  Mail goes to the log until `MAIL_*` is set to a real mail server.
- `tests/Feature/`: access rules and screens. `tests/browser/smoke.py`: browser check (Playwright).

Demo and test data are fictional only. Never real patients.
