<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Nullable. The user's "primary home" company within their tenant.
            // Set at user creation (when the tenant has at least one company)
            // or by BackfillUsersToCompanyAction. Null is recoverable — the
            // 5-branch resolution chain in ResolveCompany handles the sole-
            // company fallback case and surfaces company_required otherwise.
            $table->foreignId('default_company_id')
                ->nullable()
                ->after('current_tenant_id')
                ->constrained('companies')
                ->nullOnDelete();

            // The user's CURRENTLY-ACTIVE company. Null falls back to
            // default_company_id then to the sole-company fallback then to
            // throwing company_required. Persisted across sessions so the
            // user resumes where they left off.
            $table->foreignId('current_company_id')
                ->nullable()
                ->after('default_company_id')
                ->constrained('companies')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_company_id');
            $table->dropConstrainedForeignId('default_company_id');
        });
    }
};
