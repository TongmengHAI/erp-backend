<?php

declare(strict_types=1);

namespace App\Web\API\V1\Resources\HRM;

use App\Domain\HRM\Models\Position;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact Position shape — used in list (index) responses.
 *
 * Drops `description` (lists stay readable; detail page shows it),
 * `employees_count` (per-row count subqueries are expensive on a
 * paginated list — detail page only), and timestamps. Smaller
 * payload = faster list pages. Mirror of DepartmentBriefResource.
 *
 * @mixin Position
 */
class PositionBriefResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->title,
            'status' => $this->status->value,
        ];
    }
}
