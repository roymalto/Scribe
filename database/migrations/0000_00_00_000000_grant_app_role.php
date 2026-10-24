<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Runs first. Tables, sequences and functions the owner creates from now on are usable by
 * the app role (DB_USERNAME). Row-level security and the audit migration narrow this down.
 */
return new class extends Migration
{
    public function up(): void
    {
        $app = config('database.connections.pgsql.username');
        DB::unprepared(<<<SQL
            ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO "{$app}";
            ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO "{$app}";
            ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT EXECUTE ON FUNCTIONS TO "{$app}";
            CREATE SCHEMA IF NOT EXISTS scribe;
            GRANT USAGE ON SCHEMA scribe TO "{$app}";
            ALTER DEFAULT PRIVILEGES IN SCHEMA scribe GRANT EXECUTE ON FUNCTIONS TO "{$app}";
        SQL);
    }

    public function down(): void
    {
        $app = config('database.connections.pgsql.username');
        DB::unprepared(<<<SQL
            ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON TABLES FROM "{$app}";
            ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON SEQUENCES FROM "{$app}";
            ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON FUNCTIONS FROM "{$app}";
            DROP SCHEMA IF EXISTS scribe CASCADE;
        SQL);
    }
};
