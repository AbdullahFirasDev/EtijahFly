<?php

namespace App\Domain\Shared\Database;

use Illuminate\Database\Connection;

/**
 * Database-level safety nets that do not depend on application code:
 *
 *  - Append-only tables: UPDATE/DELETE/TRUNCATE on the ledger, audit log and booking
 *    timeline raise an exception. Corrections are made with reversing entries.
 *  - Row Level Security is enabled on every table in the public schema. Supabase exposes
 *    the schema through PostgREST to the `anon` / `authenticated` roles; with RLS on and no
 *    policies (and privileges revoked) those roles can read nothing. Laravel connects as the
 *    table owner, which bypasses RLS, so tenant isolation stays enforced in the API layer.
 *
 * Idempotent: safe to run after every migration (`php artisan platform:harden-db`).
 */
class PostgresHardening
{
    public const APPEND_ONLY_TABLES = ['ledger_transactions', 'ledger_entries', 'audit_logs', 'booking_events'];

    /** Supabase roles used by the auto-generated Data API. */
    public const EXPOSED_ROLES = ['anon', 'authenticated'];

    public function __construct(private readonly Connection $db) {}

    public function apply(): void
    {
        if ($this->db->getDriverName() !== 'pgsql') {
            return;
        }

        $this->db->unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION etijah_prevent_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Table "%" is append-only; record a reversing entry instead', TG_TABLE_NAME
                    USING ERRCODE = 'P0001';
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        foreach (self::APPEND_ONLY_TABLES as $table) {
            $this->db->unprepared(<<<SQL
                DROP TRIGGER IF EXISTS {$table}_append_only ON {$table};
                CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table}
                    FOR EACH ROW EXECUTE FUNCTION etijah_prevent_mutation();
                DROP TRIGGER IF EXISTS {$table}_no_truncate ON {$table};
                CREATE TRIGGER {$table}_no_truncate BEFORE TRUNCATE ON {$table}
                    FOR EACH STATEMENT EXECUTE FUNCTION etijah_prevent_mutation();
            SQL);
        }

        $exposedRoles = collect(self::EXPOSED_ROLES)
            ->filter(fn (string $role) => $this->db->selectOne('SELECT 1 AS ok FROM pg_roles WHERE rolname = ?', [$role]))
            ->all();

        foreach ($this->publicTables() as $table) {
            $this->db->statement("ALTER TABLE \"{$table}\" ENABLE ROW LEVEL SECURITY");
            foreach ($exposedRoles as $role) {
                $this->db->statement("REVOKE ALL ON TABLE \"{$table}\" FROM \"{$role}\"");
            }
        }
    }

    /** @return list<string> */
    public function publicTables(): array
    {
        return array_map(
            fn ($row) => $row->tablename,
            $this->db->select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename"),
        );
    }

    /** @return list<string> tables in public schema without RLS enabled */
    public function tablesWithoutRls(): array
    {
        return array_map(
            fn ($row) => $row->tablename,
            $this->db->select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND NOT rowsecurity ORDER BY tablename"),
        );
    }
}
