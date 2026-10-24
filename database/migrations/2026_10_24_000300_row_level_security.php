<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Row-level security: PostgreSQL itself decides what each person may see and change, so a missing WHERE clause in
 * the app can't show one clinic's records to another (the app role is not the table owner, so the policies always
 * apply to it). Who is asking comes from scribe.user_id (SetDatabaseUser).
 *
 *   Administrator   everything: clinics, accounts, licence verification
 *   CLINIC_ADMIN    the clinic: its details and branding, its members, its doctors' details
 *   DOCTOR          the clinic: reads; keeps their own licence details
 *   STAFF           the clinic: reads (patients and draft prescriptions come in later phases)
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- ---------------------------------------------------------------- who is asking
            CREATE FUNCTION scribe.active_user() RETURNS bigint
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, scribe AS $$
                SELECT u.id FROM users u WHERE u.id = scribe.current_user_id() AND u.active
            $$;

            CREATE FUNCTION scribe.is_admin() RETURNS boolean
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, scribe AS $$
                SELECT coalesce((SELECT u.is_admin FROM users u WHERE u.id = scribe.active_user()), false)
            $$;

            -- The signed-in person's role in a clinic (administrators: CLINIC_ADMIN everywhere), or NULL
            CREATE FUNCTION scribe.role_in(p_clinic bigint) RETURNS text
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, scribe AS $$
                SELECT CASE WHEN scribe.is_admin() THEN 'CLINIC_ADMIN'
                            ELSE (SELECT m.role FROM clinic_user m
                                   WHERE m.clinic_id = p_clinic AND m.user_id = scribe.active_user()) END
            $$;

            CREATE FUNCTION scribe.can_read(p_clinic bigint) RETURNS boolean LANGUAGE sql STABLE AS
              $$ SELECT scribe.role_in(p_clinic) IS NOT NULL $$;
            CREATE FUNCTION scribe.can_manage(p_clinic bigint) RETURNS boolean LANGUAGE sql STABLE AS
              $$ SELECT coalesce(scribe.role_in(p_clinic) = 'CLINIC_ADMIN', false) $$;

            -- Someone works in a clinic with the signed-in person (to see a colleague's licence details)
            CREATE FUNCTION scribe.works_with(p_user bigint) RETURNS boolean
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, scribe AS $$
                SELECT EXISTS (SELECT 1 FROM clinic_user a JOIN clinic_user b ON b.clinic_id = a.clinic_id
                                WHERE a.user_id = scribe.active_user() AND b.user_id = p_user)
            $$;

            -- Someone works in a clinic the signed-in person manages (to enter a doctor's details for them)
            CREATE FUNCTION scribe.manages(p_user bigint) RETURNS boolean
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, scribe AS $$
                SELECT scribe.is_admin() OR EXISTS (
                    SELECT 1 FROM clinic_user a JOIN clinic_user b ON b.clinic_id = a.clinic_id
                     WHERE a.user_id = scribe.active_user() AND a.role = 'CLINIC_ADMIN' AND b.user_id = p_user)
            $$;

            -- ---------------------------------------------------------------- clinics and members
            ALTER TABLE clinics ENABLE ROW LEVEL SECURITY;
            CREATE POLICY clinics_select ON clinics FOR SELECT USING (scribe.can_read(id));
            CREATE POLICY clinics_insert ON clinics FOR INSERT WITH CHECK (scribe.is_admin());
            CREATE POLICY clinics_update ON clinics FOR UPDATE USING (scribe.can_manage(id)) WITH CHECK (scribe.can_manage(id));
            CREATE POLICY clinics_delete ON clinics FOR DELETE USING (scribe.is_admin());

            ALTER TABLE clinic_user ENABLE ROW LEVEL SECURITY;
            CREATE POLICY clinic_user_select ON clinic_user FOR SELECT
                USING (user_id = scribe.active_user() OR scribe.can_read(clinic_id));
            CREATE POLICY clinic_user_write ON clinic_user FOR ALL
                USING (scribe.can_manage(clinic_id)) WITH CHECK (scribe.can_manage(clinic_id));

            -- ---------------------------------------------------------------- doctors
            ALTER TABLE doctors ENABLE ROW LEVEL SECURITY;
            CREATE POLICY doctors_select ON doctors FOR SELECT
                USING (user_id = scribe.active_user() OR scribe.is_admin() OR scribe.works_with(user_id));
            CREATE POLICY doctors_insert ON doctors FOR INSERT
                WITH CHECK (user_id = scribe.active_user() OR scribe.manages(user_id));
            CREATE POLICY doctors_update ON doctors FOR UPDATE
                USING (user_id = scribe.active_user() OR scribe.manages(user_id))
                WITH CHECK (user_id = scribe.active_user() OR scribe.manages(user_id));
            CREATE POLICY doctors_delete ON doctors FOR DELETE USING (scribe.is_admin());

            CREATE TRIGGER doctors_verification BEFORE INSERT OR UPDATE ON doctors
                FOR EACH ROW EXECUTE FUNCTION scribe.doctor_verification_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS doctors_verification ON doctors;
            DROP POLICY IF EXISTS doctors_select ON doctors; DROP POLICY IF EXISTS doctors_insert ON doctors;
            DROP POLICY IF EXISTS doctors_update ON doctors; DROP POLICY IF EXISTS doctors_delete ON doctors;
            ALTER TABLE doctors DISABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS clinic_user_select ON clinic_user; DROP POLICY IF EXISTS clinic_user_write ON clinic_user;
            ALTER TABLE clinic_user DISABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS clinics_select ON clinics; DROP POLICY IF EXISTS clinics_insert ON clinics;
            DROP POLICY IF EXISTS clinics_update ON clinics; DROP POLICY IF EXISTS clinics_delete ON clinics;
            ALTER TABLE clinics DISABLE ROW LEVEL SECURITY;
            DROP FUNCTION IF EXISTS scribe.manages(bigint);
            DROP FUNCTION IF EXISTS scribe.works_with(bigint);
            DROP FUNCTION IF EXISTS scribe.can_manage(bigint);
            DROP FUNCTION IF EXISTS scribe.can_read(bigint);
            DROP FUNCTION IF EXISTS scribe.role_in(bigint);
            DROP FUNCTION IF EXISTS scribe.is_admin();
            DROP FUNCTION IF EXISTS scribe.active_user();
        SQL);
    }
};
