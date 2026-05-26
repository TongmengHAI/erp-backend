<?php

declare(strict_types=1);

namespace App\Domain\HRM\Actions;

use App\Domain\HRM\Models\Branch;
use Illuminate\Support\Facades\DB;

/**
 * Single-purpose action — update one Branch. Mirror of UpdatePositionAction.
 * tenant + company are NOT mutable on update.
 */
final class UpdateBranchAction
{
    /**
     * @param  array{
     *     code?: string,
     *     name?: string,
     *     description?: string|null,
     *     address?: string|null,
     *     city?: string|null,
     *     country_code?: string|null,
     *     phone?: string|null,
     *     status?: string,
     * }  $data
     */
    public function execute(Branch $branch, array $data): Branch
    {
        return DB::transaction(function () use ($branch, $data): Branch {
            $branch->fill($data);
            $branch->save();

            return $branch->refresh();
        });
    }
}
