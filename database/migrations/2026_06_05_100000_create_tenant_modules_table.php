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
        Schema::create('tenant_modules', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->restrictOnDelete();

            // Module identifier. Free varchar to avoid an enum migration on
            // every new module (per the Session 2 plan's app-layer validation
            // decision). Validated against the LAUNCHER_APPS registry at the
            // API boundary (FormRequest + EnforceModuleEntitlement middleware)
            // instead of at the DB layer. Discipline: app ids are
            // contractual once shipped — renaming requires a data migration.
            // Documented at §10.6 update at slice-closer.
            $table->string('module_key', 32);

            // Lifecycle state. v1: 'active' | 'disabled'. Future statuses
            // (trial, module-suspended) are billing concerns deferred per
            // the explicit cuts.
            $table->string('status', 16);

            $table->timestamp('enabled_at')->nullable();

            // Actor who flipped the entitlement to active. NULLABLE — see
            // the docblock on the backfill below for the load-bearing
            // reason. NULL also handles the legitimate "system bootstrap"
            // semantics for backfilled rows.
            $table->foreignId('enabled_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });

        // Partial unique index — one row per (tenant, module) where not
        // soft-deleted. Mirrors the precedent set by HRM tables (Position,
        // Branch, Department). A soft-deleted row stays for audit; a new
        // entitlement row can be re-created without collision.
        DB::statement(
            'CREATE UNIQUE INDEX tenant_modules_tenant_module_unique
             ON tenant_modules (tenant_id, module_key)
             WHERE deleted_at IS NULL'
        );

        // Status enum CHECK — triple-stack §10.4 (DB layer). FormRequest
        // adds the matching `Rule::in([...])`; future Zod refinement
        // ships in Session 7 when the entitlement editor lands.
        DB::statement(
            "ALTER TABLE tenant_modules ADD CONSTRAINT tenant_modules_status_enum_check
             CHECK (status IN ('active', 'disabled'))"
        );

        // ─── Backfill ────────────────────────────────────────────────────
        // Every existing tenant gets an HRM-entitled row, mirroring the
        // pre-Session-2 reality (every tenant has had implicit HRM access
        // since HRM v1). Without this backfill, all existing tenant users
        // would see an empty launcher after the migration runs — same
        // shape as the Position slice's job_title → position_id migration
        // discipline (CLAUDE.md §8 calls this out by name).
        //
        // CRITICAL: enabled_by_user_id is NULL here, NOT the SA's id.
        // The standard install flow is migrate (this runs) → db:seed
        // (SuperAdminSeeder runs THEN). At THIS moment the users table
        // contains no SA — the SA hasn't been seeded yet. Trying to
        // assign enabled_by_user_id would either fail (no SA exists) or
        // require subselects that fall over for fresh installs.
        //
        // NULL is semantically accurate: "system bootstrap, no human
        // actor responsible." New rows created via the SA UI (Session 2's
        // TenantModuleController::sync) populate enabled_by_user_id with
        // the SA's id — that path's invariant is pinned separately. The
        // §10.12 trap (visible feature works in dev, breaks on fresh
        // install) is avoided here by making the column nullable AND
        // making the backfill use NULL unconditionally.
        $now = now();
        $tenantIds = DB::table('tenants')->pluck('id');
        $rows = $tenantIds->map(fn (int $id): array => [
            'tenant_id' => $id,
            'module_key' => 'hrm',
            'status' => 'active',
            'enabled_at' => $now,
            'enabled_by_user_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ])->all();

        if (! empty($rows)) {
            DB::table('tenant_modules')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_modules');
    }
};
