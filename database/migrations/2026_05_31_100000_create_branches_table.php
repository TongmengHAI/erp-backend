<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $t): void {
            $t->id();

            $t->foreignId('tenant_id')
                ->constrained('tenants')
                ->restrictOnDelete();
            $t->foreignId('company_id')
                ->constrained('companies')
                ->restrictOnDelete();

            // code: human-friendly identifier, unique within (tenant, company).
            // Same shape as departments.code and positions.code.
            $t->string('code', 32);

            $t->string('name', 255);

            // Standard descriptor — same 500-char cap as departments.description
            // and positions.description for consistency.
            $t->string('description', 500)->nullable();

            // Physical-location fields. Standard option from the slice
            // design call: enough to represent a branch without
            // overcommitting to a multi-line address model. All nullable
            // — a newly-created branch with no address yet is valid;
            // address can be filled later.
            $t->string('address', 500)->nullable();
            $t->string('city', 100)->nullable();

            // country_code as ISO 3166-1 alpha-2. varchar(2), validated
            // via regex `^[A-Z]{2}$` on the StoreBranchRequest /
            // UpdateBranchRequest — NOT enforced via DB CHECK at this
            // slice. The demo seeder uses 'KH' uppercase consistently
            // (matches the regex). If future ingestion paths (CSV
            // import, etc.) bypass the FormRequest, they MUST either
            // route through the FormRequest or add a DB CHECK constraint
            // — surfaced in the slice plan + hrm.md.
            //
            // Why no DB CHECK now: the regex validation pattern
            // ^[A-Z]{2}$ would need an explicit Postgres CHECK like
            // CHECK (country_code IS NULL OR country_code ~ '^[A-Z]{2}$')
            // which works but adds maintenance surface. The FormRequest
            // is the only ingestion path in v1 (no CSV, no bulk import);
            // adding the CHECK when needed is a small forward migration.
            $t->string('country_code', 2)->nullable();

            // Phone — varchar(32) accommodates international formats
            // with country-code prefix + spaces ("+855 23 123 456").
            // No regex validation; phones are intentionally permissive
            // (international format chaos is well-documented).
            $t->string('phone', 32)->nullable();

            $t->string('status', 16);

            $t->timestampsTz();
            $t->softDeletesTz();

            $t->index(['tenant_id', 'company_id', 'status'], 'branches_tenant_company_status_idx');
            // Supports ILIKE search against name in the canonical list query
            // (also code, but code is a unique index already).
            $t->index(['tenant_id', 'company_id', 'name'], 'branches_tenant_company_name_idx');
        });

        // Partial unique index on code — WHERE deleted_at IS NULL so
        // soft-deleted branches don't block re-creating with the same
        // code. Adopts the positions / attendance_records discipline.
        DB::statement(
            'CREATE UNIQUE INDEX branches_tenant_company_code_unique
             ON branches (tenant_id, company_id, code)
             WHERE deleted_at IS NULL'
        );

        // CHECK on status enum — defense in depth.
        DB::statement(
            "ALTER TABLE branches ADD CONSTRAINT branches_status_check
             CHECK (status IN ('active','archived'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
