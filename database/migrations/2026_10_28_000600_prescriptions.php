<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: prescriptions, their medicines, and the PDFs made from them.
 *
 *   DRAFT -> ISSUED -> CANCELLED (with a reason) or SUPERSEDED (by a corrected prescription)
 *
 * Anyone in the clinic prepares a draft for one of its doctors. Only that doctor issues it, and only with a PRC
 * licence an administrator verified and that hasn't expired, as a DOCTOR member of the clinic, and with an S2 number
 * if any medicine is a dangerous drug. Issuing gives the prescription its reference (SAMPAGUITA-2026-00001) and a
 * snapshot of the clinic, doctor, patient and medicines exactly as printed, built here, not by the app.
 *
 * From then on the database refuses any change except cancelling (by the doctor or a clinic admin) or superseding,
 * which happens when a correction that points at it is issued. Issued prescriptions are never deleted. PDFs are
 * recorded with their SHA-256 and can't be altered or removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('clinic_id')->constrained();
            $t->unsignedBigInteger('patient_id');
            $t->foreignId('doctor_user_id')->constrained('users');
            $t->string('status', 12)->default('DRAFT');
            $t->string('reference', 60)->nullable()->unique();
            $t->timestamp('issued_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->text('cancel_reason')->nullable();
            $t->foreignId('cancelled_by')->nullable()->constrained('users');
            $t->unsignedBigInteger('supersedes_id')->nullable();       // the issued prescription this one corrects
            $t->unsignedBigInteger('repeat_of_id')->nullable();        // the prescription this one repeats
            $t->text('notes')->nullable();                             // printed under the medicines
            $t->string('follow_up')->nullable();                       // printed, e.g. "Return in 2 weeks"
            $t->jsonb('snapshot')->nullable();                         // as printed, set when issued
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique(['clinic_id', 'id']);
            $t->foreign(['clinic_id', 'patient_id'])->references(['clinic_id', 'id'])->on('patients');
            $t->foreign(['clinic_id', 'supersedes_id'])->references(['clinic_id', 'id'])->on('prescriptions');
            $t->foreign(['clinic_id', 'repeat_of_id'])->references(['clinic_id', 'id'])->on('prescriptions');
            $t->index(['clinic_id', 'status']);
            $t->index('patient_id');
        });
        DB::statement("ALTER TABLE prescriptions ADD CONSTRAINT prescriptions_status_ck
                       CHECK (status IN ('DRAFT','ISSUED','CANCELLED','SUPERSEDED'))");
        DB::statement("ALTER TABLE prescriptions ADD CONSTRAINT prescriptions_cancel_reason_ck
                       CHECK (status <> 'CANCELLED' OR btrim(coalesce(cancel_reason, '')) <> '')");

        Schema::create('prescription_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('clinic_id');
            $t->unsignedBigInteger('prescription_id');
            $t->unsignedInteger('sort')->default(0);
            $t->foreignId('medication_id')->nullable()->constrained()->nullOnDelete();   // NULL = typed in
            $t->string('generic_name');
            $t->string('brand_name')->nullable();
            $t->string('strength', 60)->nullable();
            $t->string('dosage_form', 60)->nullable();
            $t->string('quantity', 40)->nullable();                    // "#30", "1 bottle"
            $t->text('sig');                                           // directions
            $t->string('duration', 60)->nullable();                    // "7 days"
            $t->boolean('is_dangerous_drug')->default(false);
            $t->timestamps();
            $t->foreign(['clinic_id', 'prescription_id'])->references(['clinic_id', 'id'])->on('prescriptions')
                ->cascadeOnDelete();
            $t->index('prescription_id');
        });

        Schema::create('prescription_documents', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('clinic_id');
            $t->unsignedBigInteger('prescription_id');
            $t->string('path');                                        // on the private disk
            $t->char('sha256', 64);
            $t->timestamp('generated_at');
            $t->foreignId('generated_by')->nullable()->constrained('users');
            $t->foreign(['clinic_id', 'prescription_id'])->references(['clinic_id', 'id'])->on('prescriptions');
            $t->index('prescription_id');
        });

        DB::unprepared(<<<'SQL'
            -- What gets printed: clinic, doctor, patient and medicines as they are at the moment of issuing
            CREATE FUNCTION scribe.prescription_snapshot(p prescriptions) RETURNS jsonb
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

            CREATE FUNCTION scribe.prescription_guard() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, scribe AS $$
            DECLARE
                v_user   bigint := scribe.active_user();
                v_doctor doctors%ROWTYPE;
                v_code   text;
                v_n      int;
                v_fixed  text[] := ARRAY['status', 'cancel_reason', 'cancelled_at', 'cancelled_by', 'updated_at'];
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'DRAFT' THEN
                        RAISE EXCEPTION 'an issued prescription cannot be deleted' USING ERRCODE = 'insufficient_privilege';
                    END IF;
                    RETURN OLD;
                END IF;

                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'DRAFT' OR NEW.reference IS NOT NULL OR NEW.issued_at IS NOT NULL
                       OR NEW.snapshot IS NOT NULL OR NEW.cancelled_at IS NOT NULL THEN
                        RAISE EXCEPTION 'a prescription starts as a draft' USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.supersedes_id IS NOT NULL AND NOT EXISTS (
                           SELECT 1 FROM prescriptions s WHERE s.id = NEW.supersedes_id AND s.status = 'ISSUED'
                              AND s.patient_id = NEW.patient_id) THEN
                        RAISE EXCEPTION 'only an issued prescription of the same patient can be corrected'
                            USING ERRCODE = 'check_violation';
                    END IF;
                    NEW.created_by := v_user;
                    RETURN NEW;
                END IF;

                -- UPDATE
                IF NEW.clinic_id IS DISTINCT FROM OLD.clinic_id THEN
                    RAISE EXCEPTION 'a prescription cannot move to another clinic' USING ERRCODE = 'check_violation';
                END IF;

                IF OLD.status = 'DRAFT' AND NEW.status = 'DRAFT' THEN
                    IF NEW.reference IS NOT NULL OR NEW.issued_at IS NOT NULL OR NEW.snapshot IS NOT NULL
                       OR NEW.cancelled_at IS NOT NULL THEN
                        RAISE EXCEPTION 'a draft has no reference or snapshot yet' USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;

                IF OLD.status = 'DRAFT' AND NEW.status = 'ISSUED' THEN
                    IF v_user IS NULL OR v_user <> NEW.doctor_user_id THEN
                        RAISE EXCEPTION 'only the prescribing doctor can issue this prescription'
                            USING ERRCODE = 'insufficient_privilege';
                    END IF;
                    IF NOT EXISTS (SELECT 1 FROM clinic_user m WHERE m.clinic_id = NEW.clinic_id AND m.user_id = v_user
                                      AND m.role = 'DOCTOR') THEN
                        RAISE EXCEPTION 'only a doctor of this clinic can issue prescriptions'
                            USING ERRCODE = 'insufficient_privilege';
                    END IF;
                    SELECT * INTO v_doctor FROM doctors WHERE user_id = v_user;
                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'enter the doctor''s licence details first' USING ERRCODE = 'check_violation';
                    END IF;
                    IF v_doctor.license_verified_at IS NULL THEN
                        RAISE EXCEPTION 'the doctor''s PRC licence has not been verified by an administrator'
                            USING ERRCODE = 'insufficient_privilege';
                    END IF;
                    IF v_doctor.prc_valid_until IS NOT NULL AND v_doctor.prc_valid_until < current_date THEN
                        RAISE EXCEPTION 'the doctor''s PRC licence has expired' USING ERRCODE = 'insufficient_privilege';
                    END IF;
                    IF NOT EXISTS (SELECT 1 FROM prescription_items i WHERE i.prescription_id = NEW.id) THEN
                        RAISE EXCEPTION 'add at least one medicine before issuing' USING ERRCODE = 'check_violation';
                    END IF;
                    IF EXISTS (SELECT 1 FROM prescription_items i WHERE i.prescription_id = NEW.id AND i.is_dangerous_drug)
                       AND btrim(coalesce(v_doctor.s2_no, '')) = '' THEN
                        RAISE EXCEPTION 'a dangerous drug needs the doctor''s S2 number on their licence details'
                            USING ERRCODE = 'insufficient_privilege';
                    END IF;
                    IF NEW.supersedes_id IS NOT NULL AND NOT EXISTS (
                           SELECT 1 FROM prescriptions s WHERE s.id = NEW.supersedes_id AND s.status = 'ISSUED') THEN
                        RAISE EXCEPTION 'the prescription this corrects is no longer issued' USING ERRCODE = 'check_violation';
                    END IF;

                    -- the clinic's next reference this year
                    PERFORM pg_advisory_xact_lock(72710003, NEW.clinic_id::int);
                    SELECT upper(c.code) || '-' || to_char(now(), 'YYYY') || '-' INTO v_code FROM clinics c WHERE c.id = NEW.clinic_id;
                    SELECT count(*) + 1 INTO v_n FROM prescriptions WHERE clinic_id = NEW.clinic_id AND reference LIKE v_code || '%';
                    NEW.reference := v_code || lpad(v_n::text, 5, '0');
                    NEW.issued_at := now();
                    NEW.cancelled_at := NULL; NEW.cancelled_by := NULL; NEW.cancel_reason := NULL;
                    NEW.snapshot := scribe.prescription_snapshot(NEW);
                    RETURN NEW;
                END IF;

                IF OLD.status = 'ISSUED' AND NEW.status IN ('CANCELLED', 'SUPERSEDED') THEN
                    -- nothing else may change with it
                    IF (to_jsonb(NEW) - v_fixed) IS DISTINCT FROM (to_jsonb(OLD) - v_fixed) THEN
                        RAISE EXCEPTION 'an issued prescription cannot be changed' USING ERRCODE = 'insufficient_privilege';
                    END IF;
                    IF NEW.status = 'CANCELLED' THEN
                        IF NOT (v_user = OLD.doctor_user_id OR scribe.can_manage(OLD.clinic_id)) THEN
                            RAISE EXCEPTION 'only the prescribing doctor or a clinic admin can cancel a prescription'
                                USING ERRCODE = 'insufficient_privilege';
                        END IF;
                        NEW.cancelled_at := now();
                        NEW.cancelled_by := v_user;
                    ELSE
                        -- superseded only by the issuing of a correction that points at it
                        IF NOT EXISTS (SELECT 1 FROM prescriptions c WHERE c.supersedes_id = OLD.id AND c.status = 'ISSUED') THEN
                            RAISE EXCEPTION 'a prescription is superseded only by issuing its correction'
                                USING ERRCODE = 'insufficient_privilege';
                        END IF;
                        NEW.cancel_reason := OLD.cancel_reason; NEW.cancelled_at := NULL; NEW.cancelled_by := NULL;
                    END IF;
                    RETURN NEW;
                END IF;

                IF OLD.status = 'DRAFT' THEN
                    RAISE EXCEPTION 'a draft can only be issued or deleted' USING ERRCODE = 'check_violation';
                END IF;
                RAISE EXCEPTION 'an issued prescription cannot be changed' USING ERRCODE = 'insufficient_privilege';
            END $$;
            CREATE TRIGGER prescriptions_guard BEFORE INSERT OR UPDATE OR DELETE ON prescriptions
                FOR EACH ROW EXECUTE FUNCTION scribe.prescription_guard();

            -- Issuing a correction supersedes the prescription it corrects
            CREATE FUNCTION scribe.prescription_supersede() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, scribe AS $$
            BEGIN
                UPDATE prescriptions SET status = 'SUPERSEDED', updated_at = now()
                 WHERE id = NEW.supersedes_id AND status = 'ISSUED';
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'the prescription this corrects is no longer issued' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NULL;
            END $$;
            CREATE TRIGGER prescriptions_supersede AFTER UPDATE ON prescriptions FOR EACH ROW
                WHEN (OLD.status = 'DRAFT' AND NEW.status = 'ISSUED' AND NEW.supersedes_id IS NOT NULL)
                EXECUTE FUNCTION scribe.prescription_supersede();

            -- Medicines change only while the prescription is a draft; a catalogue dangerous drug stays flagged
            CREATE FUNCTION scribe.prescription_item_guard() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, scribe AS $$
            DECLARE
                v_status text;
            BEGIN
                IF TG_OP = 'UPDATE' AND (NEW.prescription_id <> OLD.prescription_id OR NEW.clinic_id <> OLD.clinic_id) THEN
                    RAISE EXCEPTION 'a medicine cannot move to another prescription' USING ERRCODE = 'check_violation';
                END IF;
                -- a catalogue medicine was deleted: its link goes, the printed details stay
                IF TG_OP = 'UPDATE' AND NEW.medication_id IS NULL AND OLD.medication_id IS NOT NULL
                   AND (to_jsonb(NEW) - 'medication_id' - 'updated_at') = (to_jsonb(OLD) - 'medication_id' - 'updated_at') THEN
                    RETURN NEW;
                END IF;
                SELECT status INTO v_status FROM prescriptions
                 WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.prescription_id ELSE NEW.prescription_id END;
                -- (no row: its draft is being deleted, which the prescription's own guard allowed)
                IF FOUND AND v_status <> 'DRAFT' THEN
                    RAISE EXCEPTION 'the medicines of an issued prescription cannot be changed'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                NEW.is_dangerous_drug := NEW.is_dangerous_drug
                    OR coalesce((SELECT m.is_dangerous_drug FROM medications m WHERE m.id = NEW.medication_id), false);
                RETURN NEW;
            END $$;
            CREATE TRIGGER prescription_items_guard BEFORE INSERT OR UPDATE OR DELETE ON prescription_items
                FOR EACH ROW EXECUTE FUNCTION scribe.prescription_item_guard();

            -- ---------------------------------------------------------------- row-level security
            -- Every member of the clinic reads and prepares; the triggers above decide issuing, cancelling and locking
            ALTER TABLE prescriptions ENABLE ROW LEVEL SECURITY;
            CREATE POLICY prescriptions_all ON prescriptions FOR ALL
                USING (scribe.can_read(clinic_id)) WITH CHECK (scribe.can_read(clinic_id));

            ALTER TABLE prescription_items ENABLE ROW LEVEL SECURITY;
            CREATE POLICY prescription_items_all ON prescription_items FOR ALL
                USING (scribe.can_read(clinic_id)) WITH CHECK (scribe.can_read(clinic_id));

            -- PDFs: added for issued prescriptions, never changed or removed (no UPDATE or DELETE policy)
            ALTER TABLE prescription_documents ENABLE ROW LEVEL SECURITY;
            CREATE POLICY prescription_documents_select ON prescription_documents FOR SELECT
                USING (scribe.can_read(clinic_id));
            CREATE POLICY prescription_documents_insert ON prescription_documents FOR INSERT
                WITH CHECK (scribe.can_read(clinic_id) AND EXISTS (
                    SELECT 1 FROM prescriptions p WHERE p.id = prescription_id AND p.status <> 'DRAFT'));

            -- ---------------------------------------------------------------- audit trail (migration 000200)
            CREATE TRIGGER prescriptions_audit AFTER INSERT OR UPDATE OR DELETE ON prescriptions
                FOR EACH ROW EXECUTE FUNCTION scribe.audit_row();
            CREATE TRIGGER prescription_items_audit AFTER INSERT OR UPDATE OR DELETE ON prescription_items
                FOR EACH ROW EXECUTE FUNCTION scribe.audit_row();
            CREATE TRIGGER prescription_documents_audit AFTER INSERT OR UPDATE OR DELETE ON prescription_documents
                FOR EACH ROW EXECUTE FUNCTION scribe.audit_row();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_documents');
        Schema::dropIfExists('prescription_items');
        Schema::dropIfExists('prescriptions');
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS scribe.prescription_item_guard();
            DROP FUNCTION IF EXISTS scribe.prescription_supersede();
            DROP FUNCTION IF EXISTS scribe.prescription_guard();
            DROP FUNCTION IF EXISTS scribe.prescription_snapshot(prescriptions);
        SQL);
    }
};
