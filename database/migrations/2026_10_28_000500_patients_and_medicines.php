<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2: each clinic's patients and their allergies, and the medicine catalogue prescriptions are built from.
 *
 *   Patients, allergies   members of the clinic (CLINIC_ADMIN, DOCTOR, STAFF) read and write; only CLINIC_ADMIN
 *                         deletes. No clinic sees another's patients.
 *   Medicines             one shared catalogue (clinic_id NULL, kept by administrators) plus each clinic's own
 *                         additions (kept by its CLINIC_ADMIN). Everyone signed in reads the shared list.
 *
 * Allergies carry their patient's clinic_id (checked by a composite foreign key), so row-level security and the
 * audit trail see the clinic directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $t) {
            $t->id();
            $t->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $t->string('patient_number', 20);                          // P-000001 per clinic, given on insert
            $t->string('last_name');
            $t->string('first_name');
            $t->string('middle_name')->nullable();
            $t->date('birth_date')->nullable();
            $t->string('sex', 1)->nullable();
            $t->string('phone', 40)->nullable();
            $t->string('email')->nullable();
            $t->string('address')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['clinic_id', 'patient_number']);
            $t->unique(['clinic_id', 'id']);                           // target of the composite foreign keys
            $t->index(['clinic_id', 'last_name', 'first_name']);
        });
        DB::statement("ALTER TABLE patients ADD CONSTRAINT patients_sex_ck CHECK (sex IN ('M','F'))");

        Schema::create('patient_allergies', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('clinic_id');
            $t->unsignedBigInteger('patient_id');
            $t->string('substance');                                   // matched against medicines' generic names
            $t->string('reaction')->nullable();
            $t->string('severity', 10)->default('UNKNOWN');
            $t->string('status', 10)->default('ACTIVE');
            $t->timestamps();
            $t->foreign(['clinic_id', 'patient_id'])->references(['clinic_id', 'id'])->on('patients')->cascadeOnDelete();
            $t->index('patient_id');
        });
        DB::statement("ALTER TABLE patient_allergies ADD CONSTRAINT patient_allergies_severity_ck
                       CHECK (severity IN ('MILD','MODERATE','SEVERE','UNKNOWN'))");
        DB::statement("ALTER TABLE patient_allergies ADD CONSTRAINT patient_allergies_status_ck
                       CHECK (status IN ('ACTIVE','INACTIVE'))");

        Schema::create('medications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('clinic_id')->nullable()->constrained()->cascadeOnDelete();   // NULL = shared catalogue
            $t->string('generic_name');                                // required first (Generics Act)
            $t->string('brand_name')->nullable();
            $t->string('strength', 60)->nullable();
            $t->string('dosage_form', 60)->nullable();
            $t->string('route', 40)->nullable();
            $t->boolean('is_dangerous_drug')->default(false);          // needs the doctor's S2 number to prescribe
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->index(['clinic_id', 'generic_name']);
        });

        DB::unprepared(<<<'SQL'
            -- The next patient number in a clinic (P-000001, ...), when none is given
            CREATE FUNCTION scribe.patient_number() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, scribe AS $$
            BEGIN
                IF NEW.patient_number IS NULL OR btrim(NEW.patient_number) = '' THEN
                    PERFORM pg_advisory_xact_lock(72710002, NEW.clinic_id::int);
                    SELECT 'P-' || lpad((coalesce(max(substring(patient_number FROM '^P-(\d+)$')::int), 0) + 1)::text, 6, '0')
                      INTO NEW.patient_number FROM patients WHERE clinic_id = NEW.clinic_id;
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER patients_number BEFORE INSERT ON patients
                FOR EACH ROW EXECUTE FUNCTION scribe.patient_number();

            -- ---------------------------------------------------------------- row-level security
            ALTER TABLE patients ENABLE ROW LEVEL SECURITY;
            CREATE POLICY patients_select ON patients FOR SELECT USING (scribe.can_read(clinic_id));
            CREATE POLICY patients_insert ON patients FOR INSERT WITH CHECK (scribe.can_read(clinic_id));
            CREATE POLICY patients_update ON patients FOR UPDATE
                USING (scribe.can_read(clinic_id)) WITH CHECK (scribe.can_read(clinic_id));
            CREATE POLICY patients_delete ON patients FOR DELETE USING (scribe.can_manage(clinic_id));

            ALTER TABLE patient_allergies ENABLE ROW LEVEL SECURITY;
            CREATE POLICY patient_allergies_select ON patient_allergies FOR SELECT USING (scribe.can_read(clinic_id));
            CREATE POLICY patient_allergies_insert ON patient_allergies FOR INSERT WITH CHECK (scribe.can_read(clinic_id));
            CREATE POLICY patient_allergies_update ON patient_allergies FOR UPDATE
                USING (scribe.can_read(clinic_id)) WITH CHECK (scribe.can_read(clinic_id));
            CREATE POLICY patient_allergies_delete ON patient_allergies FOR DELETE USING (scribe.can_manage(clinic_id));

            -- Shared medicines: read by anyone signed in, kept by administrators. A clinic's own: read by its members,
            -- kept by its clinic admins.
            ALTER TABLE medications ENABLE ROW LEVEL SECURITY;
            CREATE POLICY medications_select ON medications FOR SELECT
                USING (CASE WHEN clinic_id IS NULL THEN scribe.active_user() IS NOT NULL ELSE scribe.can_read(clinic_id) END);
            CREATE POLICY medications_write ON medications FOR ALL
                USING (CASE WHEN clinic_id IS NULL THEN scribe.is_admin() ELSE scribe.can_manage(clinic_id) END)
                WITH CHECK (CASE WHEN clinic_id IS NULL THEN scribe.is_admin() ELSE scribe.can_manage(clinic_id) END);

            -- ---------------------------------------------------------------- audit trail (migration 000200)
            CREATE TRIGGER patients_audit AFTER INSERT OR UPDATE OR DELETE ON patients
                FOR EACH ROW EXECUTE FUNCTION scribe.audit_row();
            CREATE TRIGGER patient_allergies_audit AFTER INSERT OR UPDATE OR DELETE ON patient_allergies
                FOR EACH ROW EXECUTE FUNCTION scribe.audit_row();
            CREATE TRIGGER medications_audit AFTER INSERT OR UPDATE OR DELETE ON medications
                FOR EACH ROW EXECUTE FUNCTION scribe.audit_row();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_allergies');
        Schema::dropIfExists('patients');
        Schema::dropIfExists('medications');
        DB::unprepared('DROP FUNCTION IF EXISTS scribe.patient_number()');
    }
};
