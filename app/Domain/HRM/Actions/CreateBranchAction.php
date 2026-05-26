<?php

declare(strict_types=1);

namespace App\Domain\HRM\Actions;

use App\Domain\HRM\Models\Branch;
use Illuminate\Support\Facades\DB;

/**
 * Single-purpose action — create one Branch inside the current tenant +
 * company context. Mirror of CreatePositionAction / CreateDepartmentAction.
 */
final class CreateBranchAction
{
    /**
     * @param  array{
     *     code: string,
     *     name: string,
     *     description?: string|null,
     *     address?: string|null,
     *     city?: string|null,
     *     country_code?: string|null,
     *     phone?: string|null,
     *     status: string,
     * }  $data
     */
    public function execute(array $data): Branch
    {
        return DB::transaction(function () use ($data): Branch {
            $branch = new Branch;
            $branch->fill($data);
            $branch->save();

            return $branch->refresh();
        });
    }
}
