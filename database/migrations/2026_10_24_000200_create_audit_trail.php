<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The audit trail lives in the database, not in the app (as in Pinotech MDM and DayFlow): a trigger on
 * every audited table writes who changed what (old and new values) into audit_log. The app can
 * read the log but never change it, and each entry carries a SHA-256 hash of the one before, so
 * scribe.verify_audit_chain() finds any row that was altered or removed.
 *
 * Who: scribe.user_id (set per request by the app, see App\Http\Middleware\SetDatabaseUser),
 * or scribe.actor for the demo command or a background job.
 */
return new class extends Migration
{
    /** Tables to audit => columns never copied into the log. */
    private const AUDITED = [
        'users' => ['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'],
        'clinics' => [], 'clinic_user' => [], 'doctors' => [],
    ];

    public function up(): void
    {
        $app = config('database.connections.pgsql.username');

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION scribe.current_user_id() RETURNS bigint LANGUAGE sql STABLE AS
              $$ SELECT nullif(current_setting('scribe.user_id', true), '')::bigint $$;

            CREATE TABLE audit_log (
                id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                at          timestamptz NOT NULL,
                table_name  text        NOT NULL,
                action      text        NOT NULL CHECK (action IN ('INSERT','UPDATE','DELETE')),
                row_key     text,
                clinic_id   bigint,
                user_id     bigint,
                actor       text,
                old_values  jsonb,
                new_values  jsonb,
                prev_hash   text,
                hash        text        NOT NULL
            );
            CREATE INDEX audit_log_clinic_ix ON audit_log (clinic_id, id);
            CREATE INDEX audit_log_table_ix ON audit_log (table_name, row_key);

            CREATE FUNCTION scribe.audit_row() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, scribe AS $$
            DECLARE
                v_old   jsonb;
                v_new   jsonb;
                v_row   jsonb;
                v_key   text;
                v_clinic bigint;
                v_at    timestamptz := clock_timestamp();
                v_user  bigint      := scribe.current_user_id();
                v_actor text        := nullif(current_setting('scribe.actor', true), '');
                v_prev  text;
                v_hash  text;
                col     text;
            BEGIN
                IF TG_OP <> 'INSERT' THEN v_old := to_jsonb(OLD); END IF;
                IF TG_OP <> 'DELETE' THEN v_new := to_jsonb(NEW); END IF;
                IF TG_NARGS > 0 THEN
                    FOREACH col IN ARRAY TG_ARGV LOOP
                        v_old := v_old - col;
                        v_new := v_new - col;
                    END LOOP;
                END IF;
                IF TG_OP = 'UPDATE' AND v_old = v_new THEN
                    RETURN NULL;                                   -- nothing that we log changed
                END IF;

                v_row := coalesce(v_new, v_old);
                v_key := v_row->>'id';
                v_clinic := CASE WHEN TG_TABLE_NAME = 'clinics' THEN (v_row->>'id')::bigint
                                 ELSE (v_row->>'clinic_id')::bigint END;

                -- One writer at a time, so the chain has no forks.
                PERFORM pg_advisory_xact_lock(72710001);
                SELECT hash INTO v_prev FROM audit_log ORDER BY id DESC LIMIT 1;
                v_hash := scribe.audit_hash(v_prev, v_at, TG_TABLE_NAME, TG_OP, v_key, v_user, v_actor, v_old, v_new);

                INSERT INTO audit_log (at, table_name, action, row_key, clinic_id, user_id, actor,
                                       old_values, new_values, prev_hash, hash)
                VALUES (v_at, TG_TABLE_NAME, TG_OP, v_key, v_clinic, v_user, v_actor, v_old, v_new, v_prev, v_hash);
                RETURN NULL;
            END $$;

            CREATE FUNCTION scribe.audit_hash(p_prev text, p_at timestamptz, p_table text, p_action text, p_key text,
                                           p_user bigint, p_actor text, p_old jsonb, p_new jsonb)
            RETURNS text LANGUAGE sql IMMUTABLE AS $$
                SELECT encode(sha256(convert_to(concat_ws('|', coalesce(p_prev, ''), to_char(p_at AT TIME ZONE 'UTC',
                       'YYYY-MM-DD"T"HH24:MI:SS.US'), p_table, p_action, coalesce(p_key, ''), coalesce(p_user::text, ''),
                       coalesce(p_actor, ''), coalesce(p_old::text, ''), coalesce(p_new::text, '')), 'UTF8')), 'hex')
            $$;

            -- First broken entry (id), or NULL when the whole chain checks out.
            CREATE FUNCTION scribe.verify_audit_chain() RETURNS bigint
            LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public, scribe AS $$
            DECLARE
                r      audit_log%ROWTYPE;
                v_prev text;
            BEGIN
                FOR r IN SELECT * FROM audit_log ORDER BY id LOOP
                    IF r.prev_hash IS DISTINCT FROM v_prev
                       OR r.hash <> scribe.audit_hash(r.prev_hash, r.at, r.table_name, r.action, r.row_key, r.user_id,
                                                   r.actor, r.old_values, r.new_values) THEN
                        RETURN r.id;
                    END IF;
                    v_prev := r.hash;
                END LOOP;
                RETURN NULL;
            END $$;

            CREATE FUNCTION scribe.audit_log_is_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'audit_log is append-only' USING ERRCODE = 'insufficient_privilege';
            END $$;
            CREATE TRIGGER audit_log_no_update BEFORE UPDATE OR DELETE ON audit_log
                FOR EACH ROW EXECUTE FUNCTION scribe.audit_log_is_append_only();
            CREATE TRIGGER audit_log_no_truncate BEFORE TRUNCATE ON audit_log
                FOR EACH STATEMENT EXECUTE FUNCTION scribe.audit_log_is_append_only();
        SQL);

        DB::unprepared("REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON audit_log FROM \"{$app}\"");

        foreach (self::AUDITED as $table => $redact) {
            $args = implode(', ', array_map(fn ($c) => "'$c'", $redact));
            DB::unprepared("CREATE TRIGGER {$table}_audit AFTER INSERT OR UPDATE OR DELETE ON {$table}
                            FOR EACH ROW EXECUTE FUNCTION scribe.audit_row($args)");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::AUDITED) as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_audit ON {$table}");
        }
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS audit_log;
            DROP FUNCTION IF EXISTS scribe.audit_log_is_append_only();
            DROP FUNCTION IF EXISTS scribe.verify_audit_chain();
            DROP FUNCTION IF EXISTS scribe.audit_row();
            DROP FUNCTION IF EXISTS scribe.audit_hash(text, timestamptz, text, text, text, bigint, text, jsonb, jsonb);
            DROP FUNCTION IF EXISTS scribe.current_user_id();
        SQL);
    }
};
