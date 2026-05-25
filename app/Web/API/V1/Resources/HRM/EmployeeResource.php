<?php

declare(strict_types=1);

namespace App\Web\API\V1\Resources\HRM;

use App\Domain\HRM\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full-shape Employee — used in show, store, update responses.
 *
 * tenant_id and company_id are deliberately NOT exposed: the SPA already
 * knows the active tenant + company from /auth/me, and leaking those
 * identifiers in row payloads serves no UI purpose while inviting careless
 * cross-context comparisons in client code.
 *
 * @mixin Employee
 */
class EmployeeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_code' => $this->employee_code,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'job_title' => $this->job_title,
            // Nested department snapshot — flat array projection rather than
            // a nested DepartmentResource. Avoids over-fetching department
            // metadata (description, timestamps, status) into every employee
            // payload, and keeps the response shape obvious. Null when the
            // employee has no current department OR the department row is
            // soft-deleted (belongsTo respects SoftDeletes on the parent).
            'department' => $this->department
                ? [
                    'id' => $this->department->id,
                    'code' => $this->department->code,
                    'name' => $this->department->name,
                ]
                : null,
            'hire_date' => $this->hire_date->toDateString(),
            'status' => $this->status->value,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
