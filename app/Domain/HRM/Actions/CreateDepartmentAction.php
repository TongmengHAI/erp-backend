<?php

declare(strict_types=1);

namespace App\Domain\HRM\Actions;

use App\Domain\HRM\Models\Department;
use Illuminate\Support\Facades\DB;

/**
 * Single-purpose action — create one Department inside the current
 * tenant + company context. Wraps the write in a transaction so the
 * audit row + the business row commit atomically.
 *
 * tenant_id and company_id are auto-filled by BelongsToTenant +
 * BelongsToCompany on `creating`. The middleware stack (auth → tenant →
 * company) guarantees both contexts are resolved before the controller
 * invokes us, so the traits have everything they need.
 *
 * Mirrors CreateEmployeeAction's shape exactly — the HRM Action pattern
 * is "thin transaction wrapper around the Eloquent save, audit covered
 * by the trait, rollback on any failure."
 */
final class CreateDepartmentAction
{
    /**
     * @param  array{
     *     code: string,
     *     name: string,
     *     description?: string|null,
     *     status: string,
     * }  $data
     */
    public function execute(array $data): Department
    {
        return DB::transaction(function () use ($data): Department {
            $department = new Department;
            $department->fill($data);
            $department->save();

            return $department->refresh();
        });
    }
}
