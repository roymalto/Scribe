<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1: clinics, who works in them, and doctors' licence details.
 *
 * A clinic is the boundary for everything clinical that comes later (patients, prescriptions): each clinic sees only
 * its own records. A person can work in several clinics, with a role in each. Administrators (users.is_admin) run the
 * platform: clinics, accounts and licence verification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('is_admin')->default(false);
            $t->boolean('active')->default(true);
            $t->text('app_authentication_secret')->nullable();          // two-factor sign-in (encrypted by the app)
            $t->text('app_authentication_recovery_codes')->nullable();
        });

        Schema::create('clinics', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();                          // in the address: /admin/{code}
            $t->string('name');
            $t->string('address')->nullable();
            $t->string('city')->nullable();
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->string('hours')->nullable();                           // clinic hours, printed on prescriptions
            $t->string('logo_path')->nullable();                       // private disk
            $t->string('header_note')->nullable();
            $t->string('footer_note')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('clinic_user', function (Blueprint $t) {
            $t->id();
            $t->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('role', 20);
            $t->unique(['clinic_id', 'user_id']);
        });
        DB::statement("ALTER TABLE clinic_user ADD CONSTRAINT clinic_user_role_ck
                       CHECK (role IN ('CLINIC_ADMIN','DOCTOR','STAFF'))");

        // A doctor's professional details, one row per person, shared by every clinic they work in
        Schema::create('doctors', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('display_name');                                // as printed, e.g. "Juan Dela Cruz, MD, FPCP"
            $t->string('specialty')->nullable();
            $t->string('prc_license_no', 20);
            $t->date('prc_valid_until')->nullable();
            $t->string('ptr_no', 30)->nullable();                      // professional tax receipt
            $t->string('s2_no', 30)->nullable();                       // PDEA S2, for dangerous drugs
            $t->timestamp('license_verified_at')->nullable();
            $t->foreignId('license_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        DB::unprepared(<<<'SQL'
            -- Only an administrator may mark a licence verified; changing the licence number clears the verification.
            CREATE FUNCTION scribe.doctor_verification_guard() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, scribe AS $$
            BEGIN
                -- a new licence number entered by anyone but an administrator needs checking again
                IF TG_OP = 'UPDATE' AND NEW.prc_license_no IS DISTINCT FROM OLD.prc_license_no
                   AND NOT scribe.is_admin() THEN
                    NEW.license_verified_at := NULL;
                    NEW.license_verified_by := NULL;
                    RETURN NEW;
                END IF;
                IF (TG_OP = 'INSERT' AND (NEW.license_verified_at IS NOT NULL OR NEW.license_verified_by IS NOT NULL))
                   OR (TG_OP = 'UPDATE' AND (NEW.license_verified_at IS DISTINCT FROM OLD.license_verified_at
                                             OR NEW.license_verified_by IS DISTINCT FROM OLD.license_verified_by)) THEN
                    IF NOT scribe.is_admin() THEN
                        RAISE EXCEPTION 'only an administrator can verify a licence' USING ERRCODE = 'insufficient_privilege';
                    END IF;
                    NEW.license_verified_by := CASE WHEN NEW.license_verified_at IS NOT NULL THEN scribe.active_user() END;
                END IF;
                RETURN NEW;
            END $$;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS scribe.doctor_verification_guard() CASCADE');
        Schema::dropIfExists('doctors');
        Schema::dropIfExists('clinic_user');
        Schema::dropIfExists('clinics');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['is_admin', 'active', 'app_authentication_secret',
                                                                    'app_authentication_recovery_codes']));
    }
};
