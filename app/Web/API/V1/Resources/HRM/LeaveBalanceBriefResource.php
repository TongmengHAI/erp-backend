<?php

declare(strict_types=1);

namespace App\Web\API\V1\Resources\HRM;

use App\Domain\HRM\Models\LeaveBalance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact LeaveBalance shape — used in list (index) responses. The
 * computed consumed_days + remaining_days fields are populated by
 * LeaveBalanceQueryService::query()'s LEFT JOIN; the resource reads
 * them as raw model attributes.
 *
 * Nested employee snapshot keeps the list scannable without an extra
 * fetch (eager-loaded via with('employee') in the controller).
 *
 * @mixin LeaveBalance
 */
class LeaveBalanceBriefResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->employee?->full_name,
            'employee_code' => $this->employee?->employee_code,
            'leave_type' => $this->leave_type->value,
            'period_year' => $this->period_year,
            // decimal:1 cast returns a string; explicit float cast keeps
            // the JSON wire format numeric so the SPA can do math /
            // formatting without parseFloat ceremony.
            'allocated_days' => (float) $this->allocated_days,
            // Computed via LeaveBalanceQueryService. Fallback to allocated
            // if the row was fetched bare (not through the service) — the
            // resource still renders sensibly. The two raw attributes
            // ride alongside the model's columns when the JOIN is present.
            'consumed_days' => (float) ($this->consumed_days ?? 0),
            // Negative when over-consumed. Wire format preserves the
            // sign — Session 2 renders the negative case explicitly.
            'remaining_days' => (float) ($this->remaining_days ?? (float) $this->allocated_days),
        ];
    }
}
