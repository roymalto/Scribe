<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Tells PostgreSQL who is acting, for row-level security and the audit trail (scribe.user_id, scribe.actor,
 * scribe.display_department; see migrations 000200 and 000300). Web requests set it in SetDatabaseUser; the public
 * display sets only its department. Commands and queued jobs call asUser() / set() themselves.
 */
class DatabaseContext
{
    public static function set(?int $userId, ?string $actor = null, ?int $displayDepartment = null): void
    {
        DB::select(
            "SELECT set_config('scribe.user_id', ?, false), set_config('scribe.actor', ?, false),
                    set_config('scribe.display_department', ?, false)",
            [(string) ($userId ?? ''), (string) ($actor ?? ''), (string) ($displayDepartment ?? '')]
        );
    }

    public static function clear(): void
    {
        self::set(null);
    }

    // The public waiting-room display of one department: its resources, appointments and notices, no patients
    public static function display(int $departmentId): void
    {
        self::set(null, 'display', $departmentId);
    }

    /** Run $fn as a user, then clear the context. */
    public static function asUser(int $userId, callable $fn, ?string $actor = null): mixed
    {
        self::set($userId, $actor);
        try {
            return $fn();
        } finally {
            self::clear();
        }
    }
}
