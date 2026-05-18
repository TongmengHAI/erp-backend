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
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('slug', 63);
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->char('country_code', 2);
            $table->char('default_currency', 3);
            $table->char('functional_currency', 3);
            $table->string('timezone', 64);
            $table->string('status', 16)->default('active');
            $table->jsonb('settings')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            // Slugs are unique within a tenant. Cross-tenant slug collision
            // is allowed — Acme Trading Co. as a slug can exist in two
            // unrelated tenants.
            $table->unique(['tenant_id', 'slug']);
            // Composite index for the hot path: ResolveCompany middleware
            // looks up by (tenant_id, status) frequently.
            $table->index(['tenant_id', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE companies ADD CONSTRAINT companies_status_check
            CHECK (status IN ('active', 'archived'))
        SQL);

        DB::statement(<<<'SQL'
            COMMENT ON COLUMN companies.settings IS
            'Shape: { feature_flags?: { [key: string]: bool }, branding?: { logo_url?: string }, fiscal_year_start_month?: int 1-12 }. Future per-company settings (separate fiscal calendar from tenant default, etc.).'
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
