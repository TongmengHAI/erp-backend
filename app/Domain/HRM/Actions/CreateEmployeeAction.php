<?php

declare(strict_types=1);

namespace App\Domain\HRM\Actions;

use App\Domain\HRM\Models\Employee;
use App\Domain\HRM\Services\HrmSettingsRepository;
use App\Domain\HRM\Support\EmployeeCodeGenerator;
use App\Support\Company\CompanyContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Single-purpose action — create one Employee inside the current
 * tenant + company context. Wraps the write in a transaction so the
 * audit row + the business row commit atomically.
 *
 * Two code-source paths driven by HrmSettings:
 *
 *   • auto_generate_employee_code = false (default):
 *       The caller supplies $data['employee_code']. Current behavior.
 *
 *   • auto_generate_employee_code = true:
 *       The Action reads next_value from hrm_employee_code_sequences
 *       under SELECT FOR UPDATE, formats {prefix}{next_value}, and
 *       inserts the employee with that code. The increment + insert
 *       are atomic — both rollback if either fails. The DB::transaction
 *       around the lock + insert is what makes concurrency safe:
 *       two simultaneous calls block on each other, the second sees
 *       the incremented next_value after the first commits.
 *
 *       Caller MUST NOT supply $data['employee_code'] in auto-gen mode;
 *       the FormRequest enforces this (prohibited rule), and a
 *       defensive InvalidArgumentException catches direct Action calls
 *       that bypass the FormRequest.
 *
 * tenant_id and company_id are auto-filled by BelongsToTenant +
 * BelongsToCompany on `creating`; this action does NOT pass them in
 * explicitly. The middleware stack (auth → tenant → company) guarantees
 * both contexts are resolved before the controller invokes us, so the
 * traits have everything they need.
 *
 * Throws — never partial-creates:
 *   - InvalidArgumentException when auto-gen is on and the caller
 *     supplied employee_code (caught above; defensive).
 *   - QueryException on unique-violation (manual-mode employee_code
 *     collision). Controller maps to 422.
 *   - AuditWriteFailedException if the audit row fails. Rolls back the
 *     business row with it (defense in depth, §4 / Auditable trait).
 */
final class CreateEmployeeAction
{
    public function __construct(
        private readonly HrmSettingsRepository $settingsRepository,
        private readonly EmployeeCodeGenerator $codeGenerator,
        private readonly TenantContext $tenantContext,
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * @param  array{
     *     employee_code?: string,
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
        $settings = $this->settingsRepository->getForCurrentCompany();

        return DB::transaction(function () use ($data, $settings): Employee {
            if ($settings->auto_generate_employee_code) {
                if (! empty($data['employee_code'])) {
                    throw new InvalidArgumentException(
                        'employee_code is auto-generated for this company; '
                        .'do not provide a value. The StoreEmployeeRequest '
                        .'rejects this at the API layer; this defensive '
                        .'guard catches direct Action calls that bypassed it.'
                    );
                }
                // Lock + read + increment, atomic with the employee
                // insert below. The DB::transaction wrapping this
                // closure is what gives SELECT FOR UPDATE its meaning.
                $data['employee_code'] = $this->codeGenerator->next(
                    $this->tenantContext->currentId(),
                    $this->companyContext->currentId(),
                    $settings->employee_code_prefix,
                );
            }

            $employee = new Employee;
            $employee->fill($data);
            $employee->save();

            return $employee->refresh();
        });
    }
}
