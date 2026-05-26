<?php

declare(strict_types=1);

namespace App\Web\API\V1\Resources\HRM;

use App\Domain\HRM\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full-shape Branch — used in show, store, update responses.
 *
 * Mirror of PositionResource with the additional physical-location
 * fields. employees_count is the load-bearing field for the detail
 * page's "Employees at this branch" section.
 *
 * @mixin Branch
 */
class BranchResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'address' => $this->address,
            'city' => $this->city,
            'country_code' => $this->country_code,
            'phone' => $this->phone,
            'status' => $this->status->value,
            'employees_count' => $this->employees_count,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
