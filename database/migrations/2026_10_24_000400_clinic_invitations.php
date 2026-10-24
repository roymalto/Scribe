<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nobody joins a clinic without agreeing to (Roy, 2026-10-24). A clinic admin invites an email address with a role;
 * the person opens the emailed link and accepts or declines, signing in first or, without an account yet, creating
 * one. Only the link's token (its SHA-256) is stored.
 *
 * The database enforces it: clinic admins can no longer add rows to clinic_user themselves. Memberships come from
 * scribe.accept_invitation(), run as the invited person, or from an administrator (setup, demo data).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_invitations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $t->string('email');
            $t->string('role', 20);
            $t->string('token_hash', 64)->unique();
            $t->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('expires_at');
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('declined_at')->nullable();
            $t->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['clinic_id', 'email']);
        });
        DB::statement("ALTER TABLE clinic_invitations ADD CONSTRAINT clinic_invitations_role_ck
                       CHECK (role IN ('CLINIC_ADMIN','DOCTOR','STAFF'))");

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION scribe.my_email() RETURNS text
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, scribe AS $$
                SELECT lower(u.email) FROM users u WHERE u.id = scribe.active_user()
            $$;

            -- Clinic admins see and manage their clinic's invitations; the invited person sees their own
            ALTER TABLE clinic_invitations ENABLE ROW LEVEL SECURITY;
            CREATE POLICY clinic_invitations_select ON clinic_invitations FOR SELECT
                USING (scribe.can_manage(clinic_id) OR lower(email) = scribe.my_email());
            CREATE POLICY clinic_invitations_write ON clinic_invitations FOR ALL
                USING (scribe.can_manage(clinic_id)) WITH CHECK (scribe.can_manage(clinic_id));

            -- Memberships: clinic admins change roles and remove people, but only an invitation (or an administrator)
            -- adds someone
            DROP POLICY clinic_user_write ON clinic_user;
            CREATE POLICY clinic_user_insert ON clinic_user FOR INSERT WITH CHECK (scribe.is_admin());
            CREATE POLICY clinic_user_update ON clinic_user FOR UPDATE
                USING (scribe.can_manage(clinic_id)) WITH CHECK (scribe.can_manage(clinic_id));
            CREATE POLICY clinic_user_delete ON clinic_user FOR DELETE USING (scribe.can_manage(clinic_id));

            -- What an invitation link is for, before anyone signs in: clinic, email, role and whether it can still be used
            CREATE FUNCTION scribe.invitation_for_token(p_token_hash text)
            RETURNS TABLE (id bigint, clinic_name text, email text, role text, status text)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, scribe AS $$
                SELECT i.id, c.name, i.email, i.role,
                       CASE WHEN i.accepted_at IS NOT NULL THEN 'accepted'
                            WHEN i.declined_at IS NOT NULL THEN 'declined'
                            WHEN i.expires_at < now() OR NOT c.active THEN 'expired'
                            ELSE 'open' END
                  FROM clinic_invitations i JOIN clinics c ON c.id = i.clinic_id
                 WHERE i.token_hash = p_token_hash
            $$;

            -- The signed-in person answers an invitation sent to their email. Returns the clinic's id.
            CREATE FUNCTION scribe.answer_invitation(p_token_hash text, p_accept boolean) RETURNS bigint
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, scribe AS $$
            DECLARE
                v_inv  clinic_invitations%ROWTYPE;
                v_user bigint := scribe.active_user();
            BEGIN
                SELECT * INTO v_inv FROM clinic_invitations WHERE token_hash = p_token_hash FOR UPDATE;
                IF NOT FOUND OR v_inv.accepted_at IS NOT NULL OR v_inv.declined_at IS NOT NULL
                   OR v_inv.expires_at < now()
                   OR NOT EXISTS (SELECT 1 FROM clinics WHERE id = v_inv.clinic_id AND active) THEN
                    RAISE EXCEPTION 'this invitation can no longer be used' USING ERRCODE = 'check_violation';
                END IF;
                IF v_user IS NULL OR lower(v_inv.email) <> scribe.my_email() THEN
                    RAISE EXCEPTION 'this invitation is for another email address' USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF p_accept THEN
                    INSERT INTO clinic_user (clinic_id, user_id, role) VALUES (v_inv.clinic_id, v_user, v_inv.role)
                        ON CONFLICT (clinic_id, user_id) DO UPDATE SET role = EXCLUDED.role;
                    UPDATE clinic_invitations SET accepted_at = now(), answered_by = v_user, updated_at = now()
                     WHERE id = v_inv.id;
                ELSE
                    UPDATE clinic_invitations SET declined_at = now(), answered_by = v_user, updated_at = now()
                     WHERE id = v_inv.id;
                END IF;
                RETURN v_inv.clinic_id;
            END $$;

            CREATE TRIGGER clinic_invitations_audit AFTER INSERT OR UPDATE OR DELETE ON clinic_invitations
                FOR EACH ROW EXECUTE FUNCTION scribe.audit_row('token_hash');
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS scribe.answer_invitation(text, boolean);
            DROP FUNCTION IF EXISTS scribe.invitation_for_token(text);
            DROP POLICY IF EXISTS clinic_user_insert ON clinic_user;
            DROP POLICY IF EXISTS clinic_user_update ON clinic_user;
            DROP POLICY IF EXISTS clinic_user_delete ON clinic_user;
            CREATE POLICY clinic_user_write ON clinic_user FOR ALL
                USING (scribe.can_manage(clinic_id)) WITH CHECK (scribe.can_manage(clinic_id));
        SQL);
        Schema::dropIfExists('clinic_invitations');
        DB::unprepared('DROP FUNCTION IF EXISTS scribe.my_email()');
    }
};
