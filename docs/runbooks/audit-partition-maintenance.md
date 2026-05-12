# Audit partition maintenance

`audit_logs` uses Postgres native declarative partitioning (`PARTITION BY RANGE (created_at)`) with one partition per month. Partitions are named `audit_logs_YYYY_MM`.

This document is the operational playbook for keeping the rolling window healthy and detaching old partitions for archival.

## Components

| Piece | Purpose |
|---|---|
| Initial migration (`2026_05_12_120300_create_audit_logs_table.php`) | Creates 14 months of partitions on first install: previous + current + next 12. |
| `audit:partitions:rollover` artisan command | Idempotent. Creates next N months (default 3) if missing. Safe to run any time. |
| `Schedule::command('audit:partitions:rollover')->monthlyOn(25, '02:00')` (`routes/console.php`) | Production schedule. Runs monthly on the 25th at 02:00. |

## Required prod setup

`php artisan schedule:run` must be invoked every minute by cron on the production VPS:

```cron
* * * * * cd /var/www/erp/current && php artisan schedule:run >> /dev/null 2>&1
```

If this cron lapses, the audit_logs partition window stops extending. With the migration's initial 14-month lookahead, a fresh install can survive **about a year** of cron downtime before INSERTs start failing for "now". After that, audit row inserts will fail with:

```
SQLSTATE[23514]: Check violation: no partition of relation "audit_logs" found for row
```

— which is a §G **fail-loud** signal, not a silent miss. Business writes that audit (every model with `Auditable`) will fail and roll back. Outage-visible.

## Health check

Confirm the rolling window is healthy:

```sql
SELECT child.relname AS partition_name,
       pg_get_expr(child.relpartbound, child.oid) AS range
FROM pg_inherits i
JOIN pg_class parent ON parent.oid = i.inhparent
JOIN pg_class child  ON child.oid  = i.inhrelid
WHERE parent.relname = 'audit_logs'
ORDER BY child.relname;
```

Should return at least the current month plus 3 future months at all times.

## Recovery if the schedule has lapsed

Run the rollover command manually with a larger window:

```bash
php artisan audit:partitions:rollover --months=12
```

Then verify the cron is running again. If it isn't, fix the cron — the lapse will recur.

## Archival of old partitions (designed-in, executed later)

The partition naming convention (`audit_logs_YYYY_MM`) supports clean detachment. Periodic archival (e.g. detaching partitions older than 7 years per a compliance policy) is a Postgres-native operation:

```sql
-- 1. Detach the partition. It becomes a standalone table.
ALTER TABLE audit_logs DETACH PARTITION audit_logs_2019_01;

-- 2. Optionally back it up.
pg_dump -t audit_logs_2019_01 -f audit_logs_2019_01.sql erp

-- 3. Optionally drop it.
DROP TABLE audit_logs_2019_01;
```

A future `audit:partitions:archive {--older-than-months=N}` artisan command should encapsulate steps 1+2 (dropping is a separate manual step requiring sign-off). **Not implemented in slice 6** — designed-in via the naming convention, executed when compliance requires it.

## Why no DEFAULT partition

The schema deliberately omits a `DEFAULT` partition. A default partition would silently absorb rows that fall outside the rolling window, and once that happens you can't add a covering RANGE partition without **moving rows** between partitions — a manual, error-prone operation. Without a default, the partition window must be maintained; failure surfaces immediately as a write error rather than silently routing rows to a default they don't belong in.

## Immutability

Every partition is subject to a `BEFORE UPDATE OR DELETE` trigger that raises `'audit_logs is append-only; UPDATE and DELETE are blocked by design.'`. The trigger is defined on the parent table and **automatically propagated to all current and future partitions** by Postgres 13+. Don't try to write audit-fixing scripts — they will fail by design. To correct an audited entity, emit a **new** audit row with `action='updated'` referencing the original.
