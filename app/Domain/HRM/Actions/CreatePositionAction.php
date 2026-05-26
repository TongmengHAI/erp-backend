<?php

declare(strict_types=1);

namespace App\Domain\HRM\Actions;

use App\Domain\HRM\Models\Position;
use Illuminate\Support\Facades\DB;

/**
 * Single-purpose action — create one Position inside the current
 * tenant + company context. Mirror of CreateDepartmentAction.
 *
 * tenant_id and company_id are auto-filled by BelongsToTenant +
 * BelongsToCompany on `creating`.
 */
final class CreatePositionAction
{
    /**
     * @param  array{
     *     code: string,
     *     title: string,
     *     description?: string|null,
     *     status: string,
     * }  $data
     */
    public function execute(array $data): Position
    {
        return DB::transaction(function () use ($data): Position {
            $position = new Position;
            $position->fill($data);
            $position->save();

            return $position->refresh();
        });
    }
}
