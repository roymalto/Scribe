<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An administrator may accept an invitation on the invited person's behalf (Roy, 2026-10-31), e.g. a doctor who
 * can't get to the link. Clinic admins still can't: only the invited person or an administrator. The invitation
 * records who answered it (answered_by = the administrator), and the audit trail keeps the change.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Accept an open invitation for the account with the invited email (which must exist). Returns the clinic's id.
            CREATE FUNCTION scribe.accept_invitation_for(p_invitation_id bigint) RETURNS bigint
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, scribe AS $$
            DECLARE
                v_inv     clinic_invitations%ROWTYPE;
                v_invitee bigint;
            BEGIN
                IF NOT scribe.is_admin() THEN
                    RAISE EXCEPTION 'only an administrator can accept for someone else' USING ERRCODE = 'insufficient_privilege';
                END IF;
                SELECT * INTO v_inv FROM clinic_invitations WHERE id = p_invitation_id FOR UPDATE;
                IF NOT FOUND OR v_inv.accepted_at IS NOT NULL OR v_inv.declined_at IS NOT NULL
                   OR v_inv.expires_at < now()
                   OR NOT EXISTS (SELECT 1 FROM clinics WHERE id = v_inv.clinic_id AND active) THEN
                    RAISE EXCEPTION 'this invitation can no longer be used' USING ERRCODE = 'check_violation';
                END IF;
                SELECT id INTO v_invitee FROM users WHERE lower(email) = lower(v_inv.email);
                IF v_invitee IS NULL THEN
                    RAISE EXCEPTION 'no account has the invited email yet' USING ERRCODE = 'check_violation';
                END IF;

                INSERT INTO clinic_user (clinic_id, user_id, role) VALUES (v_inv.clinic_id, v_invitee, v_inv.role)
                    ON CONFLICT (clinic_id, user_id) DO UPDATE SET role = EXCLUDED.role;
                UPDATE clinic_invitations SET accepted_at = now(), answered_by = scribe.active_user(), updated_at = now()
                 WHERE id = v_inv.id;
                RETURN v_inv.clinic_id;
            END $$;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS scribe.accept_invitation_for(bigint)');
    }
};
