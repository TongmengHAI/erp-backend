<?php

declare(strict_types=1);

namespace App\Domain\HRM\Actions;

use App\Domain\HRM\Models\Department;
use Illuminate\Support\Facades\DB;

/**
 * Single-purpose action — update one Department. Wrapped in a transaction
 * so the dirty-diff audit row commits atomically with the business write.
 *
 * Tenant + company are NOT mutable on update — the request validation
 * doesn't accept them, and even if it did, BelongsToTenant /
 * BelongsToCompany only auto-fill on `creating`. Moving a department
 * between companies would be a re-org workflow, not a routine edit.
 */
final class UpdateDepartmentAction
{
    /**
     * @param  array{
     *     code?: string,
     *     name?: string,
     *     description?: string|null,
     *     status?: string,
     * }  $data
     */
    public function execute(Department $department, array $data): Department
    {
        return DB::transaction(function () use ($department, $data): Department {
            $department->fill($data);
            $department->save();

            return $department->refresh();
        });
    }
}
