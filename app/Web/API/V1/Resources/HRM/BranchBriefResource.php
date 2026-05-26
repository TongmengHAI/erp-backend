<?php

declare(strict_types=1);

namespace App\Web\API\V1\Resources\HRM;

use App\Domain\HRM\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact Branch shape — used in list (index) responses.
 *
 * Drops `description`, address/country/phone, employees_count, and
 * timestamps. INCLUDES `city` because location is the at-a-glance
 * differentiator between branches with similar names (e.g. multiple
 * "Sales Office" rows in different cities). This makes BranchBrief
 * one field wider than the corresponding Department / Position brief
 * shapes — a deliberate departure justified by the domain.
 *
 * @mixin Branch
 */
class BranchBriefResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'city' => $this->city,
            'status' => $this->status->value,
        ];
    }
}
