<?php

declare(strict_types=1);

namespace App\Domain\HRM\Actions;

use App\Domain\HRM\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * Single-purpose action — create one Employee inside the current
 * tenant + company context. Wraps the write in a transaction so the
 * audit row + the business row commit atomically.
 *
 * tenant_id and company_id are auto-filled by BelongsToTenant +
 * BelongsToCompany on `creating`; this action does NOT pass them in
 * explicitly. The middleware stack (auth → tenant → company) guarantees
 * both contexts are resolved before the controller invokes us, so the
 * traits have everything they need.
 *
 * Throws — never partial-creates:
 *   - QueryException on unique-violation (employee_code already in use
 *     within this company). Controller maps to 422.
 *   - AuditWriteFailedException if the audit row fails. Rolls back the
 *     business row with it (defense in depth, §4 / Auditable trait).
 */
final class CreateEmployeeAction
{
    /**
     * @param  array{
     *     employee_code: string,
     *     full_name: string,
     *     email?: string|null,
     *     department_id?: int|null,
     *     position_id?: int|null,
     *     branch_id?: int|null,
     *     hire_date: string,
     *     status: string,
     * }  $data
     */
    public function execute(array $data): Employee
    {
        return DB::transaction(function () use ($data): Employee {
            $employee = new Employee;
            $employee->fill($data);
            $employee->save();

            return $employee->refresh();
        });
    }
}
