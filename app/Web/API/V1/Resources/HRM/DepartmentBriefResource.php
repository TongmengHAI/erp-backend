<?php

declare(strict_types=1);

namespace App\Web\API\V1\Resources\HRM;

use App\Domain\HRM\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact Department shape — used in list (index) responses. Drops
 * `description` (lists stay readable; the description shows on the
 * detail page) and the timestamp pair (the list table doesn't render
 * "last edited" columns). Smaller payload = faster list pages.
 *
 * @mixin Department
 */
class DepartmentBriefResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'status' => $this->status->value,
        ];
    }
}
