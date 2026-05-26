<?php

declare(strict_types=1);

namespace App\Web\API\V1\Resources\HRM;

use App\Domain\HRM\Models\LeaveBalance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full-shape LeaveBalance — used in show / store / update responses.
 *
 * Adds notes + timestamps + nested employee snapshot on top of the
 * brief shape. consumed_days + remaining_days are computed by
 * LeaveBalanceQueryService and ride alongside the model attributes
 * — same wire shape as the brief.
 *
 * @mixin LeaveBalance
 */
class LeaveBalanceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee' => $this->employee
                ? [
                    'id' => $this->employee->id,
                    'employee_code' => $this->employee->employee_code,
                    'full_name' => $this->employee->full_name,
                ]
                : null,
            'leave_type' => $this->leave_type->value,
            'period_year' => $this->period_year,
            'allocated_days' => (float) $this->allocated_days,
            'consumed_days' => (float) ($this->consumed_days ?? 0),
            'remaining_days' => (float) ($this->remaining_days ?? (float) $this->allocated_days),
            'notes' => $this->notes,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
