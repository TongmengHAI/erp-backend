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
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 63)->unique();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->char('country_code', 2);
            $table->char('default_currency', 3);
            $table->char('functional_currency', 3);
            $table->string('timezone', 64);
            $table->string('status', 16)->default('active')->index();
            $table->jsonb('settings')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE tenants ADD CONSTRAINT tenants_status_check
            CHECK (status IN ('active', 'suspended', 'archived'))
        SQL);

        DB::statement(<<<'SQL'
            COMMENT ON COLUMN tenants.settings IS
            'Shape: { feature_flags?: { [key: string]: bool }, branding?: { logo_url?: string }, fiscal_year_start_month?: int 1-12 }'
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
