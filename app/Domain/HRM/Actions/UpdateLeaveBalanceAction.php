<?php

declare(strict_types=1);

namespace App\Domain\HRM\Actions;

use App\Domain\HRM\Models\LeaveBalance;
use Illuminate\Support\Facades\DB;

/**
 * Update non-FK fields on a LeaveBalance. Editing allocated_days /
 * notes is allowed; changing the identity tuple (employee_id +
 * leave_type + period_year) would conceptually create a different
 * row, so those fields fall outside this action's $data signature.
 *
 * Mirror of UpdateBranchAction. Diff-only audit row produced by the
 * Auditable trait.
 */
final class UpdateLeaveBalanceAction
{
    /**
     * @param  array{
     *     allocated_days?: float|string,
     *     notes?: string|null,
     * }  $data
     */
    public function execute(LeaveBalance $balance, array $data): LeaveBalance
    {
        return DB::transaction(function () use ($balance, $data): LeaveBalance {
            $balance->fill($data);
            $balance->save();

            return $balance->refresh();
        });
    }
}
