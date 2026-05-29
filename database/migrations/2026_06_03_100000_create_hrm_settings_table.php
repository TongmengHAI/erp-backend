<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-company HRM settings. One row per (tenant_id, company_id),
     * created on Company creation by BootstrapHrmSettingsListener
     * (listening for the CompanyCreated event fired from the Company
     * model's `booted()` hook). Existing companies at deploy time are
     * backfilled in this same migration's up().
     *
     * No SoftDeletes on this table — settings are 1:1 with Company,
     * and a deleted company doesn't have a meaningful "restore my
     * settings" flow. Justification deliberately captured here so the
     * trait-stack inconsistency with other HRM tables doesn't trip
     * review: this row persists when Company is soft-deleted (the
     * companies FK is restrictOnDelete, not cascading on soft-delete),
     * and on Company restoration the prior settings row is still in
     * place — no data loss either direction. Plain UNIQUE constraint
     * (no `WHERE deleted_at IS NULL` partial) is correct because the
     * settings row itself isn't soft-deletable.
     *
     * Two settings ship in v1:
     *   • auto_generate_employee_code (bool) — when true,
     *     CreateEmployeeAction generates {prefix}{next_value} using the
     *     hrm_employee_code_sequences table. The user does NOT supply
     *     employee_code in the form when this is on.
     *   • employee_code_prefix (varchar 8, nullable) — REQUIRED when
     *     auto-gen is true. Constrained alphabet (uppercase, digits,
     *     hyphen, underscore) so generated codes stay URL-safe and
     *     DB-search-friendly.
     *   • default_employee_status (enum) — the initial status the form
     *     pre-fills when creating a new employee.
     *
     * Two settings deferred to future slices (documented in hrm.md):
     *   • Minimum Employee Age — needs birth_date on Employee
     *   • Auto-Generated Display Name — needs first_name/last_name split
     */
    public function up(): void
    {
        Schema::create('hrm_settings', function (Blueprint $t): void {
            $t->id();

            $t->foreignId('tenant_id')
                ->constrained('tenants')
                ->restrictOnDelete();
            $t->foreignId('company_id')
                ->constrained('companies')
                ->restrictOnDelete();

            // Behavior flag — when true, the Employee form omits the
            // employee_code input and CreateEmployeeAction generates one
            // via the sequence table.
            $t->boolean('auto_generate_employee_code')->default(false);

            // Prefix string. Nullable in the column shape so off-state
            // companies (the default) don't need a placeholder value.
            // Required by composite CHECK + FormRequest cross-field rule
            // when auto_generate_employee_code is true. Alphabet
            // constrained to [A-Z0-9_-] so the eventual generated code
            // is URL-safe + reads cleanly in tables. Max 8 chars caps
            // visual width without compressing the numeric counter
            // (room for 7-char prefix + dash + up to 999,999,999 codes
            // before column-width issues hit). v1 default is NULL
            // (admin sets it explicitly when turning auto-gen on).
            $t->string('employee_code_prefix', 8)->nullable();

            // Default status the Employee form pre-fills. The status
            // enum itself is enforced on the employees table — this
            // CHECK below mirrors it so settings can't drift to a value
            // the Employee form can't actually use.
            $t->string('default_employee_status', 16)->default('active');

            $t->timestampsTz();

            // No deleted_at column. See file-level docblock.
        });

        // Plain UNIQUE — no `WHERE deleted_at IS NULL` partial because
        // the row isn't soft-deletable. The 1:1 with Company is the
        // natural key; one settings row per company exists or doesn't.
        DB::statement(
            'CREATE UNIQUE INDEX hrm_settings_unique_company
             ON hrm_settings (tenant_id, company_id)'
        );

        // CHECK 1 — enum subset on default_employee_status. Triple-stack
        // discipline per CLAUDE.md §3 (DB CHECK + FormRequest rule + Zod
        // refinement, three layers). DB layer is the final backstop.
        DB::statement(
            "ALTER TABLE hrm_settings ADD CONSTRAINT hrm_settings_default_status_check
             CHECK (default_employee_status IN ('active','on_leave','terminated'))"
        );

        // CHECK 2 — alphabet constraint on the prefix. Same regex the
        // FormRequest enforces. Generated codes will always be
        // URL-safe + search-friendly. NULL allowed (off-state companies).
        DB::statement(
            "ALTER TABLE hrm_settings ADD CONSTRAINT hrm_settings_prefix_alphabet_check
             CHECK (employee_code_prefix IS NULL OR employee_code_prefix ~ '^[A-Z0-9_-]+\$')"
        );

        // CHECK 3 (LOAD-BEARING) — composite cross-field consistency.
        // auto-gen on REQUIRES a non-null prefix. This is the rule
        // CreateEmployeeAction's auto-gen branch depends on: if it
        // reaches an auto-gen row, the prefix is guaranteed non-null.
        // FormRequest closure + Zod refinement enforce this at higher
        // layers; the DB CHECK is the final guard against direct SQL,
        // careless seeders, or any future ingestion path that bypasses
        // the Action/Request.
        DB::statement(
            'ALTER TABLE hrm_settings ADD CONSTRAINT hrm_settings_autogen_prefix_consistency_check
             CHECK (auto_generate_employee_code = false OR employee_code_prefix IS NOT NULL)'
        );

        // Backfill existing companies. New companies created from this
        // point onward get rows via BootstrapHrmSettingsListener; this
        // covers the at-deploy population. Idempotent via the unique
        // index — re-running after manual fixup raises a unique
        // violation, which is the right behavior for a one-shot
        // migration (don't silently swallow duplicates).
        DB::statement(
            "INSERT INTO hrm_settings
                (tenant_id, company_id, auto_generate_employee_code,
                 employee_code_prefix, default_employee_status,
                 created_at, updated_at)
             SELECT tenant_id, id, false, NULL, 'active', NOW(), NOW()
             FROM companies"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('hrm_settings');
    }
};
