<?php

declare(strict_types=1);

namespace App\Web\API\V1\Resources\HRM;

use App\Domain\HRM\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full-shape Department — used in show, store, update responses.
 *
 * tenant_id and company_id are deliberately NOT exposed: the SPA already
 * knows the active tenant + company from /auth/me, and leaking those
 * identifiers in row payloads serves no UI purpose while inviting careless
 * cross-context comparisons in client code.
 *
 * Mirrors EmployeeResource's shape conventions.
 *
 * @mixin Department
 */
class DepartmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status->value,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
