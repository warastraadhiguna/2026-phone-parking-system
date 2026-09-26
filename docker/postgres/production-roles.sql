-- Production database roles (Phase 11; ADR-0007 "no hidden bypass"). Run once as a superuser
-- (or the managed-DB admin), then again after migrations that add tables (the GRANT ... ON ALL
-- TABLES lines are idempotent). Not used by the local docker-compose setup.
--
--   pati_owner    owns the schema; used ONLY by `php artisan migrate` during deployment.
--   pati_app      used by php-fpm, queue workers and the scheduler at runtime.
--
-- The runtime role can read and write business data, but cannot change the schema, cannot
-- TRUNCATE anything, and cannot UPDATE or DELETE append-only tables. The database triggers
-- enforce the same rules for every role; the grants make a trigger bypass impossible for the
-- application, because pati_app cannot DROP or DISABLE a trigger it does not own.
--
-- Replace the passwords with values from the secret manager. Never commit real passwords.
--
-- Usage: psql -v dbname=pati_parking -v owner_password=… -v app_password=… -f production-roles.sql

-- Idempotent: the script can be re-run after every migration that adds tables.
SELECT format('CREATE ROLE pati_owner LOGIN PASSWORD %L', :'owner_password') WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'pati_owner') \gexec
SELECT format('CREATE ROLE pati_app LOGIN PASSWORD %L', :'app_password') WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'pati_app') \gexec

ALTER DATABASE :"dbname" OWNER TO pati_owner;
\connect :dbname
ALTER SCHEMA public OWNER TO pati_owner;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO pati_app;

-- Business data: read and write.
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO pati_app;
GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO pati_app;

-- Append-only tables: insert and read only. Financial records: never deleted (their triggers
-- also refuse it). Applied to the tables that exist (the first run happens before migrations).
SELECT format('REVOKE UPDATE, DELETE ON %I FROM pati_app', t) FROM unnest(ARRAY[
    'audit_logs', 'cash_ledger_entries', 'payment_adjustments', 'payment_provider_events',
    'reconciliation_runs', 'reconciliation_lines', 'reconciliation_mismatches']) AS t
WHERE to_regclass('public.' || t) IS NOT NULL \gexec
SELECT format('REVOKE DELETE ON %I FROM pati_app', t) FROM unnest(ARRAY[
    'parking_transactions', 'payments', 'cash_settlements', 'void_requests', 'anomaly_reviews', 'tariffs']) AS t
WHERE to_regclass('public.' || t) IS NOT NULL \gexec

-- Tables created by future migrations (run as pati_owner) get the same default grants.
-- Re-run the REVOKE block above after a migration that adds an append-only table.
ALTER DEFAULT PRIVILEGES FOR ROLE pati_owner IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO pati_app;
ALTER DEFAULT PRIVILEGES FOR ROLE pati_owner IN SCHEMA public GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO pati_app;

-- TRUNCATE is never granted to pati_app (it is not part of the grants above).
