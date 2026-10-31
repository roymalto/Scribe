<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The local edition: Scribe installed on one Windows PC for a doctor's own use (Roy, 2026-10-31). There the doctor is
 * the only administrator, so nobody else can verify their PRC licence: issuing doesn't need a verified licence
 * (still a current one). Everything else stays the same.
 *
 * Whether this install is the local edition lives in scribe.settings, which only the table owner writes (the
 * seeder, from SCRIBE_EDITION=local); the app's own role can't read or change it except through local_edition().
 * The first-run screen makes the first account through first_run_setup(), which refuses once anyone has one.
 */
return new class extends Migration
{
    private const ONLINE = 'IF v_doctor.license_verified_at IS NULL THEN';
    private const LOCAL = 'IF v_doctor.license_verified_at IS NULL AND NOT scribe.local_edition() THEN';

    // The issue guard of migration 000600 with one condition swapped (the rest of the function is unchanged)
    private function swapGuard(string $from, string $to): void
    {
        $def = DB::selectOne("SELECT pg_get_functiondef('scribe.prescription_guard()'::regprocedure) AS def")->def;
        if (substr_count($def, $from) !== 1) {
            throw new RuntimeException("prescription_guard: expected the line [{$from}] exactly once");
        }
        DB::unprepared(str_replace($from, $to, $def));
    }

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE scribe.settings (key text PRIMARY KEY, value text NOT NULL);

            CREATE FUNCTION scribe.local_edition() RETURNS boolean
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, scribe AS $$
                SELECT coalesce((SELECT value = 'true' FROM scribe.settings WHERE key = 'local_edition'), false)
            $$;

            -- The local edition's first run: the doctor's account (the install's administrator), their clinic, their
            -- membership as its doctor and their licence details. Only in the local edition and only while there
            -- are no accounts at all. Returns the new account's id.
            CREATE FUNCTION scribe.first_run_setup(p_clinic_name text, p_clinic_code text, p_clinic_address text,
                p_clinic_phone text, p_name text, p_specialty text, p_prc_license_no text, p_prc_valid_until date,
                p_email text, p_password_hash text) RETURNS bigint
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, scribe AS $$
            DECLARE
                v_user   bigint;
                v_clinic bigint;
            BEGIN
                PERFORM pg_advisory_xact_lock(72710010);         -- two first runs at once: the second finds an account
                IF NOT scribe.local_edition() OR EXISTS (SELECT 1 FROM users) THEN
                    RAISE EXCEPTION 'Scribe is already set up' USING ERRCODE = 'insufficient_privilege';
                END IF;
                INSERT INTO users (name, email, password, is_admin, active, created_at, updated_at)
                    VALUES (p_name, lower(p_email), p_password_hash, true, true, now(), now()) RETURNING id INTO v_user;
                INSERT INTO clinics (name, code, address, phone, created_at, updated_at)
                    VALUES (p_clinic_name, p_clinic_code, p_clinic_address, p_clinic_phone, now(), now())
                    RETURNING id INTO v_clinic;
                INSERT INTO clinic_user (clinic_id, user_id, role) VALUES (v_clinic, v_user, 'DOCTOR');
                INSERT INTO doctors (user_id, display_name, specialty, prc_license_no, prc_valid_until, created_at, updated_at)
                    VALUES (v_user, p_name, p_specialty, p_prc_license_no, p_prc_valid_until, now(), now());
                RETURN v_user;
            END $$;
        SQL);
        $this->swapGuard(self::ONLINE, self::LOCAL);
    }

    public function down(): void
    {
        $this->swapGuard(self::LOCAL, self::ONLINE);
        DB::unprepared('DROP FUNCTION IF EXISTS scribe.first_run_setup(text, text, text, text, text, text, text, date, text, text);
                        DROP FUNCTION IF EXISTS scribe.local_edition(); DROP TABLE IF EXISTS scribe.settings;');
    }
};
