<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The prescription is headed by the doctor (name, specialty), with every clinic they practise at underneath in small
 * print, like a printed Rx pad (Roy, 2026-10-31). The snapshot gains doctor_clinics: the doctor's active clinics
 * where they are a DOCTOR member, the issuing clinic first, as they were when issued.
 */
return new class extends Migration
{
    private function snapshot(bool $withClinics): string
    {
        $clinics = $withClinics ? <<<'SQL'
                    'doctor_clinics', (SELECT coalesce(jsonb_agg(jsonb_build_object('name', c.name, 'address', c.address,
                                           'city', c.city, 'phone', c.phone, 'email', c.email, 'hours', c.hours)
                                           ORDER BY c.id = p.clinic_id DESC, c.name), '[]'::jsonb)
                                         FROM clinics c JOIN clinic_user cu ON cu.clinic_id = c.id
                                        WHERE cu.user_id = p.doctor_user_id AND cu.role = 'DOCTOR' AND c.active),
SQL : '';

        return <<<SQL
            CREATE OR REPLACE FUNCTION scribe.prescription_snapshot(p prescriptions) RETURNS jsonb
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, scribe AS \$\$
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
{$clinics}
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
            \$\$;
SQL;
    }

    public function up(): void
    {
        DB::unprepared($this->snapshot(true));
    }

    public function down(): void
    {
        DB::unprepared($this->snapshot(false));                // as in migration 000800
    }
};
