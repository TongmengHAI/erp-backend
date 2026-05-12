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
            // tenant_id is the user's HOME tenant (set at creation, immutable).
            // Nullable so super-admin/system users can exist without a tenant;
            // typical users will always have it set.
            $table->foreignId('tenant_id')
                ->nullable()
                ->constrained('tenants')
                ->restrictOnDelete();

            // current_tenant_id is the user's CURRENTLY-ACTIVE tenant. NULL means
            // "fall back to tenant_id". Slice 6 will populate this on tenant switch.
            $table->foreignId('current_tenant_id')
                ->nullable()
                ->constrained('tenants')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id']);
            $table->dropForeign(['current_tenant_id']);
            $table->dropColumn(['tenant_id', 'current_tenant_id']);
        });
    }
};
