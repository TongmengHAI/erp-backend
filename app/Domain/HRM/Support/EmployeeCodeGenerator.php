<?php

declare(strict_types=1);

namespace App\Domain\HRM\Support;

use App\Domain\HRM\Models\HrmEmployeeCodeSequence;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Generates the next employee code for a company's auto-gen sequence.
 *
 * Concurrency safety relies on `SELECT FOR UPDATE` (row-level lock)
 * inside a `DB::transaction`. Two simultaneous calls for the same
 * company:
 *
 *   1. First transaction begins, acquires the row lock on the sequence,
 *      reads `next_value`, increments, commits, releases.
 *   2. Second transaction blocks on the lock during step 1, then sees
 *      the incremented `next_value` once the first commits.
 *
 * No way for two concurrent calls to receive the same code.
 *
 * Lazy init: the sequence row is created on first use via firstOrCreate.
 * The unique index on (tenant_id, company_id) means a race during
 * firstOrCreate produces at most one row.
 *
 * Extracted as a standalone class (rather than living on
 * CreateEmployeeAction) for the same reasons LeaveDaysCalculator was
 * extracted: testable in isolation, single source of truth for the
 * code-format rule, swap implementations later (e.g. zero-padded
 * formatting `TT-0001`) without touching the Action.
 *
 * Caller MUST invoke from inside an already-open DB::transaction so
 * the lock + the Employee insert are atomic. The Action documents this;
 * the generator asserts via the lockForUpdate semantics (will issue
 * a SELECT FOR UPDATE that has no effect outside a transaction —
 * a defensive guard could trip on `DB::transactionLevel() === 0` but
 * v1 keeps the generator simple and trusts the caller).
 */
final class EmployeeCodeGenerator
{
    /**
     * Generate the next code as `{prefix}{next_value}` and increment
     * the counter. Atomic with the caller's transaction.
     *
     * @throws InvalidArgumentException when $prefix is null/empty —
     *                                  the caller (Action) should only invoke this on auto-gen
     *                                  enabled, and the DB CHECK guarantees prefix is non-null
     *                                  in that case. Defensive throw matches the contract.
     * @throws RuntimeException if invoked outside a transaction. The
     *                          lockForUpdate would silently no-op otherwise.
     */
    public function next(int $tenantId, int $companyId, ?string $prefix): string
    {
        if ($prefix === null || $prefix === '') {
            throw new InvalidArgumentException(
                'EmployeeCodeGenerator::next requires a non-empty prefix. '
                .'Caller invoked auto-gen path with a settings row that lacks a prefix; '
                .'this should have been caught by the DB CHECK constraint hrm_settings_autogen_prefix_consistency_check.'
            );
        }

        if (DB::transactionLevel() === 0) {
            throw new RuntimeException(
                'EmployeeCodeGenerator::next must be invoked from within a DB::transaction — '
                .'the SELECT FOR UPDATE lock is meaningless outside one.'
            );
        }

        /** @var HrmEmployeeCodeSequence $sequence */
        $sequence = HrmEmployeeCodeSequence::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->firstOrCreate(
                ['tenant_id' => $tenantId, 'company_id' => $companyId],
                ['next_value' => 1],
            );

        $value = $sequence->next_value;
        $sequence->increment('next_value');

        return $prefix.$value;
    }
}
