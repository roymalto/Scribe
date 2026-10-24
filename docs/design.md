# Scribe: design

Electronic prescriptions for independent clinics and doctors in the Philippines. Started 2026-10-24 from a
ChatGPT design Roy shared ("ePrescription Philippines, Version 1"), built on the same base as Pinotech MDM and
DayFlow instead of that design's MySQL + Bootstrap stack.

## What it does (version 1)

- Several clinics in one install. A doctor can work in several clinics; each clinic sees only its own records.
- Clinic branding (name, address, hours, logo, header and footer lines) printed on its prescriptions.
- Doctors' professional details: PRC licence (number, valid until), PTR, S2. An administrator marks a licence
  verified after checking the PRC verification site; changing the number clears it.
- Patients and allergies, a medicine catalogue, prescriptions built from it, and branded PDF prescriptions for
  doctors to download and send (Viber, Messenger, email).
- Repeat prescriptions from medication history; cancellation with a reason; full audit trail.
- Phone, tablet and desktop.

Not in version 1: sending to pharmacies or a national e-prescribing service, legally verified e-signatures,
automatic repeats, drug-interaction checking.

## Changes from the ChatGPT design

| ChatGPT design | Scribe | Why |
|---|---|---|
| MySQL, isolation by app code (policies) | PostgreSQL 17 row-level security per clinic, plus Filament checks | A missing filter in the app can't leak another clinic's patients |
| Blade + Bootstrap screens | Filament 5 (Laravel 13) | Same as MDM / DayFlow: forms, tables, phone layout, 2FA for free |
| Audit log written by the app | Database triggers, hash-chained, append-only | Can't be skipped or edited by the app |
| Google sign-in in phase 1 | Email + password, authenticator-app 2FA (required for administrators) | Google sign-in can follow; it needs a Google Cloud client |
| nginx + PHP-FPM | Apache + mod_php in one container | Same images as the other apps |

## Roles

| Role | Where | Can |
|---|---|---|
| Administrator (`users.is_admin`) | Platform | Every clinic; accounts; verify licences |
| CLINIC_ADMIN | A clinic | Clinic details and branding, staff, doctors' details |
| DOCTOR | A clinic | Their own licence details; (phase 3) issue prescriptions |
| STAFF | A clinic | Read; (phase 2) register patients; (phase 3) prepare drafts for a doctor |

## Phases

1. **Foundation (done 2026-10-24):** clinics as tenants, staff and roles, doctors' licence details and
   verification, 2FA, RLS, audit, demo data (`php artisan scribe:demo`).
2. **Patients and medicines:** patients per clinic (number, name, birth date, sex, contact, address), allergies,
   medicine catalogue (generic, brand, strength, form, route), search.
3. **Prescribing and PDF:** draft builder, issue (locked afterwards: a database trigger), cancel with reason,
   supersede, repeat from history; PDF from the issued snapshot (Dompdf), stored privately with a SHA-256 hash;
   only clinic members download it.
4. **Readiness:** backups and restore test, privacy impact assessment (Data Privacy Act), clinical acceptance tests,
   S2 / dangerous-drug rules, prescription template checked against current FDA / DOH rules.

## Prescription states (phase 3)

DRAFT -> ISSUED -> CANCELLED or SUPERSEDED. Only a verified doctor of the clinic issues. An issued prescription keeps
a snapshot of the medicines, doctor and clinic details as printed; corrections make a new prescription that
supersedes it.
