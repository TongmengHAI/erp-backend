<?php

declare(strict_types=1);

namespace App\Web\API\V1\Resources\HRM;

use App\Domain\HRM\Models\Position;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full-shape Position — used in show, store, update responses.
 *
 * Mirror of DepartmentResource. employees_count is the load-bearing
 * field for the detail page's "Employees with this position" section;
 * PositionController::show pre-loads via ->loadCount('employees') so
 * this is a single attribute read on serialisation, not a per-render
 * subquery.
 *
 * @mixin Position
 */
class PositionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value,
            'employees_count' => $this->employees_count,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
