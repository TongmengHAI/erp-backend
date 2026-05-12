<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function countAuditPartitions(): int
{
    /** @var array<int, object{count: int}> $rows */
    $rows = DB::select(<<<'SQL'
        SELECT COUNT(*) AS count
        FROM pg_inherits i
        JOIN pg_class parent ON parent.oid = i.inhparent
        WHERE parent.relname = 'audit_logs'
    SQL);

    return (int) $rows[0]->count;
}

it('creates partitions for the next N months that do not yet exist', function (): void {
    // Migration ships 14 months (last + current + next 12). Asking for 24 months
    // forces creation of 11 new partitions (months 13–23 ahead).
    $countBefore = countAuditPartitions();

    $this->artisan('audit:partitions:rollover', ['--months' => 24])
        ->assertSuccessful();

    $countAfter = countAuditPartitions();
    expect($countAfter)->toBeGreaterThan($countBefore);
});

it('is idempotent — running twice creates no extra partitions', function (): void {
    $this->artisan('audit:partitions:rollover', ['--months' => 6])->assertSuccessful();
    $countAfterFirst = countAuditPartitions();

    $this->artisan('audit:partitions:rollover', ['--months' => 6])->assertSuccessful();
    $countAfterSecond = countAuditPartitions();

    expect($countAfterSecond)->toBe($countAfterFirst);
});

it('inserting an audit row whose created_at has no covering partition fails LOUD', function (): void {
    // The migration covers (last month) → (now + 12 months). 50 years out
    // is guaranteed to be outside every partition.
    expect(fn () => DB::transaction(fn () => DB::table('audit_logs')->insert([
        'tenant_id' => null,
        'auditable_type' => 'Test',
        'auditable_id' => 1,
        'action' => 'created',
        'created_at' => '2099-01-15 12:00:00+00',
    ])))->toThrow(QueryException::class, 'no partition');
});
