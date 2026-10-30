<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A doctor's signature image, printed above the signature line of their prescriptions (Roy, 2026-10-31).
 *
 * Only the doctor sets or replaces it: a clinic admin or staff member must not be able to put a doctor's signature
 * on prescriptions. An administrator may only remove one. Files live in doctor-signatures/<user id>/. Issued prescriptions keep the path in their snapshot, so
 * signature files are never deleted: a reprint shows the signature as it was when issued.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctors', function (Blueprint $t) {
            $t->string('signature_path')->nullable();
        });

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION scribe.doctor_signature_guard() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, scribe AS $$
            BEGIN
                IF NEW.signature_path IS DISTINCT FROM (CASE WHEN TG_OP = 'UPDATE' THEN OLD.signature_path END)
                   AND NEW.user_id IS DISTINCT FROM scribe.active_user()
                   AND NOT (NEW.signature_path IS NULL AND scribe.is_admin()) THEN
                    RAISE EXCEPTION 'only the doctor can set their signature' USING ERRCODE = 'insufficient_privilege';
                END IF;
                -- uploads go to the doctor's own folder; never point at someone else's file
                IF NEW.signature_path IS NOT NULL AND NEW.signature_path NOT LIKE 'doctor-signatures/' || NEW.user_id || '/%' THEN
                    RAISE EXCEPTION 'a signature must be in the doctor''s own folder' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$;

            CREATE TRIGGER doctors_signature BEFORE INSERT OR UPDATE ON doctors
                FOR EACH ROW EXECUTE FUNCTION scribe.doctor_signature_guard();

            -- As in migration 000600, plus the doctor's signature
            CREATE OR REPLACE FUNCTION scribe.prescription_snapshot(p prescriptions) RETURNS jsonb
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, scribe AS $$
                SELECT jsonb_build_object(
                    'reference', p.reference,
                    'issued_at', to_char(p.issued_at, 'YYYY-MM-DD"T"HH24:MI:SS'),
                    'notes', p.notes,
                    'follow_up', p.follow_up,
                    'supersedes', (SELECT s.reference FROM prescriptions s WHERE s.id = p.supersedes_id),
                    'clinic', (SELECT jsonb_build_object('code', c.code, 'name', c.name, 'address', c.address,
                                   'city', c.city, 'phone', c.phone, 'email', c.email, 'hours', c.hours,
                                   'header_note', c.header_note, 'footer_note', c.footer_note, 'logo_path', c.logo_path)
                                 FROM clinics c WHERE c.id = p.clinic_id),
                    'doctor', (SELECT jsonb_build_object('name', d.display_name, 'specialty', d.specialty,
                                   'prc_license_no', d.prc_license_no, 'prc_valid_until', d.prc_valid_until,
                                   'ptr_no', d.ptr_no, 's2_no', d.s2_no, 'signature_path', d.signature_path)
                                 FROM doctors d WHERE d.user_id = p.doctor_user_id),
                    'patient', (SELECT jsonb_build_object('number', x.patient_number, 'last_name', x.last_name,
                                    'first_name', x.first_name, 'middle_name', x.middle_name, 'birth_date', x.birth_date,
                                    'age', CASE WHEN x.birth_date IS NOT NULL
                                                THEN date_part('year', age(p.issued_at::date, x.birth_date))::int END,
                                    'sex', x.sex, 'address', x.address)
                                  FROM patients x WHERE x.id = p.patient_id),
                    'items', (SELECT coalesce(jsonb_agg(jsonb_build_object('generic_name', i.generic_name,
                                  'brand_name', i.brand_name, 'strength', i.strength, 'dosage_form', i.dosage_form,
                                  'quantity', i.quantity, 'sig', i.sig, 'duration', i.duration,
                                  'is_dangerous_drug', i.is_dangerous_drug) ORDER BY i.sort, i.id), '[]'::jsonb)
                                FROM prescription_items i WHERE i.prescription_id = p.id))
            $$;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS doctors_signature ON doctors;
            DROP FUNCTION IF EXISTS scribe.doctor_signature_guard();
            CREATE OR REPLACE FUNCTION scribe.prescription_snapshot(p prescriptions) RETURNS jsonb
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, scribe AS $$
                SELECT jsonb_build_object(
                    'reference', p.reference,
                    'issued_at', to_char(p.issued_at, 'YYYY-MM-DD"T"HH24:MI:SS'),
                    'notes', p.notes,
                    'follow_up', p.follow_up,
                    'supersedes', (SELECT s.reference FROM prescriptions s WHERE s.id = p.supersedes_id),
                    'clinic', (SELECT jsonb_build_object('code', c.code, 'name', c.name, 'address', c.address,
                                   'city', c.city, 'phone', c.phone, 'email', c.email, 'hours', c.hours,
                                   'header_note', c.header_note, 'footer_note', c.footer_note, 'logo_path', c.logo_path)
                                 FROM clinics c WHERE c.id = p.clinic_id),
                    'doctor', (SELECT jsonb_build_object('name', d.display_name, 'specialty', d.specialty,
                                   'prc_license_no', d.prc_license_no, 'prc_valid_until', d.prc_valid_until,
                                   'ptr_no', d.ptr_no, 's2_no', d.s2_no)
                                 FROM doctors d WHERE d.user_id = p.doctor_user_id),
                    'patient', (SELECT jsonb_build_object('number', x.patient_number, 'last_name', x.last_name,
                                    'first_name', x.first_name, 'middle_name', x.middle_name, 'birth_date', x.birth_date,
                                    'age', CASE WHEN x.birth_date IS NOT NULL
                                                THEN date_part('year', age(p.issued_at::date, x.birth_date))::int END,
                                    'sex', x.sex, 'address', x.address)
                                  FROM patients x WHERE x.id = p.patient_id),
                    'items', (SELECT coalesce(jsonb_agg(jsonb_build_object('generic_name', i.generic_name,
                                  'brand_name', i.brand_name, 'strength', i.strength, 'dosage_form', i.dosage_form,
                                  'quantity', i.quantity, 'sig', i.sig, 'duration', i.duration,
                                  'is_dangerous_drug', i.is_dangerous_drug) ORDER BY i.sort, i.id), '[]'::jsonb)
                                FROM prescription_items i WHERE i.prescription_id = p.id))
            $$;
        SQL);
        Schema::table('doctors', function (Blueprint $t) {
            $t->dropColumn('signature_path');
        });
    }
};
