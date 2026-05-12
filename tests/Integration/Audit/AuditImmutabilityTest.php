<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Support\Audit\Models\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\AuditTestWidget;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Schema::create('audit_test_widgets', function (Blueprint $t): void {
        $t->id();
        $t->unsignedBigInteger('tenant_id')->nullable();
        $t->string('name');
        $t->timestampsTz();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('audit_test_widgets');
});

// NOTE: each "expected to fail" DB call is wrapped in DB::transaction(...) which
// opens a SAVEPOINT inside the outer RefreshDatabase transaction. When the
// trigger raises an exception, only the savepoint rolls back — the outer txn
// stays alive, and afterEach's Schema::dropIfExists works. Without this,
// Postgres marks the whole txn as aborted and every subsequent query fails.

it('UPDATE on audit_logs raises a QueryException — DB trigger blocks modification', function (): void {
    $tenant = Tenant::factory()->create();
    AuditTestWidget::create(['name' => 'first', 'tenant_id' => $tenant->id]);

    expect(fn () => DB::transaction(
        fn () => DB::table('audit_logs')->update(['action' => 'tampered'])
    ))->toThrow(QueryException::class, 'append-only');
});

it('DELETE on audit_logs raises a QueryException — DB trigger blocks deletion', function (): void {
    $tenant = Tenant::factory()->create();
    AuditTestWidget::create(['name' => 'first', 'tenant_id' => $tenant->id]);

    expect(fn () => DB::transaction(
        fn () => DB::table('audit_logs')->delete()
    ))->toThrow(QueryException::class, 'append-only');
});

it('AuditLog Eloquent model refuses creating/updating/deleting events (Eloquent-level guard)', function (): void {
    // The DB trigger is the real guard; this proves AuditLog::create() throws a
    // clearer LogicException before ever reaching the DB.
    expect(fn () => AuditLog::query()->create(['action' => 'fake']))
        ->toThrow(LogicException::class, 'AuditWriter::record()');
});
