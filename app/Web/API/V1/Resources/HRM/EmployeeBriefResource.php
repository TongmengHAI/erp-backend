<?php

declare(strict_types=1);

namespace App\Web\API\V1\Resources\HRM;

use App\Domain\HRM\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact Employee shape — used in list (index) responses. Drops
 * created_at / updated_at because list rows render a Hire Date column,
 * not a "last edited" column. Smaller payload = faster list pages.
 *
 * @mixin Employee
 */
class EmployeeBriefResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_code' => $this->employee_code,
            'full_name' => $this->full_name,
            // department_name (not _code) — codes next to position_title
            // would create visual code-twin noise (E-1001 / D-OPS /
            // Operations Manager all in the same row). The name reads
            // natural. Eager-loaded via with('department') on the
            // controller's index query to avoid N+1.
            'department_name' => $this->department?->name,
            // position_title — replaces the old free-text job_title column
            // (dropped in the Positions slice). Same flat-name pattern as
            // department_name; eager-loaded via with('position') in the
            // controller to avoid N+1.
            'position_title' => $this->position?->title,
            'hire_date' => $this->hire_date->toDateString(),
            'status' => $this->status->value,
        ];
    }
}
