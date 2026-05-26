<?php

declare(strict_types=1);

namespace App\Domain\HRM\Actions;

use App\Domain\HRM\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * Single-purpose action — update one Employee. Wrapped in a transaction
 * so the dirty-diff audit row commits atomically with the business write.
 *
 * Tenant + company are NOT mutable on update — the request validation
 * doesn't accept them, and even if it did, BelongsToTenant /
 * BelongsToCompany only auto-fill on `creating`. Switching an employee
 * between companies would be a re-org workflow, not a routine edit.
 */
final class UpdateEmployeeAction
{
    /**
     * @param  array{
     *     employee_code?: string,
     *     full_name?: string,
     *     email?: string|null,
     *     department_id?: int|null,
     *     position_id?: int|null,
     *     branch_id?: int|null,
     *     hire_date?: string,
     *     status?: string,
     * }  $data
     */
    public function execute(Employee $employee, array $data): Employee
    {
        return DB::transaction(function () use ($employee, $data): Employee {
            $employee->fill($data);
            $employee->save();

            return $employee->refresh();
        });
    }
}
