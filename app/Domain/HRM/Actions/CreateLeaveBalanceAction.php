<?php

declare(strict_types=1);

namespace App\Domain\HRM\Actions;

use App\Domain\HRM\Models\LeaveBalance;
use Illuminate\Support\Facades\DB;

/**
 * Single-purpose action — create one LeaveBalance inside the current
 * tenant + company context. Mirror of CreateBranchAction.
 *
 * tenant_id + company_id are auto-filled by the BelongsToTenant +
 * BelongsToCompany traits on creating. The FormRequest validates
 * leave_type is in the allocated subset; the DB CHECK is the final
 * guard against direct SQL bypass.
 */
final class CreateLeaveBalanceAction
{
    /**
     * @param  array{
     *     employee_id: int,
     *     leave_type: string,
     *     period_year: int,
     *     allocated_days: float|string,
     *     notes?: string|null,
     * }  $data
     */
    public function execute(array $data): LeaveBalance
    {
        return DB::transaction(function () use ($data): LeaveBalance {
            $balance = new LeaveBalance;
            $balance->fill($data);
            $balance->save();

            return $balance->refresh();
        });
    }
}
