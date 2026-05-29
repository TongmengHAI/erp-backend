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
        Schema::table('users', function (Blueprint $table): void {
            // Identity discriminator. Two values for v1:
            //   - 'tenant_user': normal user belonging to a tenant. Has
            //     tenant_id NOT NULL; current_tenant_id, default_company_id,
            //     and current_company_id are nullable per existing semantics.
            //   - 'super_admin': vendor-side platform operator. All four
            //     tenant/company FK columns MUST be NULL. Bypasses
            //     TenantScope, ResolveTenant, and ResolveCompany.
            //
            // Default 'tenant_user' so every existing row backfills cleanly
            // (every user prior to this slice was a tenant user). Width 16
            // mirrors EmployeeStatus / TenantStatus column width convention.
            $table->string('type', 16)->default('tenant_user')->after('email_verified_at');
        });

        // CHECK 1 — enum subset on the discriminator. Final backstop
        // against typos in seeders or direct SQL writes. Triple-stack
        // discipline per §10.4 (DB CHECK + future FormRequest rule +
        // future Zod refinement when the SA-create UI ships).
        DB::statement(
            "ALTER TABLE users ADD CONSTRAINT users_type_enum_check
             CHECK (type IN ('tenant_user', 'super_admin'))"
        );

        // CHECK 2 (LOAD-BEARING) — composite cross-field consistency for
        // super-admin users. SA is a vendor-side platform identity; they
        // own no tenant + no company context. All four FK columns MUST be
        // NULL. This is the rule TenantScope's SA early-out + the
        // ResolveTenant/ResolveCompany bypasses depend on: if a super_admin
        // row exists, no tenant/company FK is set, so the scoping
        // middleware has nothing to misresolve.
        DB::statement(
            "ALTER TABLE users ADD CONSTRAINT users_super_admin_no_tenant_or_company_check
             CHECK (
                type <> 'super_admin'
                OR (
                    tenant_id IS NULL
                    AND current_tenant_id IS NULL
                    AND default_company_id IS NULL
                    AND current_company_id IS NULL
                )
             )"
        );

        // CHECK 3 — symmetric invariant on tenant_user. Every non-SA user
        // must have a home tenant_id. The base tenant_id column is already
        // nullable (it was relaxed in 2026_05_12 specifically to allow SA
        // users); this CHECK is the symmetric guard that prevents a
        // tenant_user from existing without tenant scope.
        DB::statement(
            "ALTER TABLE users ADD CONSTRAINT users_tenant_user_has_tenant_check
             CHECK (type <> 'tenant_user' OR tenant_id IS NOT NULL)"
        );
    }

    public function down(): void
    {
        // Forward-only in prod per §3, but dev rollback is supported.
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_tenant_user_has_tenant_check');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_super_admin_no_tenant_or_company_check');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_type_enum_check');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('type');
        });
    }
};
