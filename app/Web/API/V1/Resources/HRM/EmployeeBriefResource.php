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
            'job_title' => $this->job_title,
            'hire_date' => $this->hire_date->toDateString(),
            'status' => $this->status->value,
        ];
    }
}
