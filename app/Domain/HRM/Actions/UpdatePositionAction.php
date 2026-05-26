<?php

declare(strict_types=1);

namespace App\Domain\HRM\Actions;

use App\Domain\HRM\Models\Position;
use Illuminate\Support\Facades\DB;

/**
 * Single-purpose action — update one Position. Mirror of UpdateDepartmentAction.
 *
 * tenant + company are NOT mutable on update — the request validation
 * doesn't accept them, and the traits only auto-fill on `creating`.
 */
final class UpdatePositionAction
{
    /**
     * @param  array{
     *     code?: string,
     *     title?: string,
     *     description?: string|null,
     *     status?: string,
     * }  $data
     */
    public function execute(Position $position, array $data): Position
    {
        return DB::transaction(function () use ($position, $data): Position {
            $position->fill($data);
            $position->save();

            return $position->refresh();
        });
    }
}
