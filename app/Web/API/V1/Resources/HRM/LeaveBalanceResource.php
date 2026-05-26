<?php

declare(strict_types=1);

namespace App\Web\API\V1\Resources\HRM;

use App\Domain\HRM\Models\LeaveBalance;
use App\Domain\HRM\Models\LeaveRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Full-shape LeaveBalance — used in show / store / update responses.
 *
 * Adds notes + timestamps + nested employee snapshot on top of the
 * brief shape. consumed_days + remaining_days are computed by
 * LeaveBalanceQueryService and ride alongside the model attributes
 * — same wire shape as the brief.
 *
 * consuming_leave_requests: the approved LRs that contribute to
 * consumed_days. Populated by LeaveBalanceController::show via the
 * `consumingLeaveRequests` relation set as an eager-loaded
 * attribute (NOT a stored field on the model). Surfaces as an array
 * of brief LR snapshots so the detail page's "Consuming Leave
 * Requests" section renders in a single round-trip without a
 * separate fetch from the page component.
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
            // Consuming approved LRs — empty array when the show
            // endpoint didn't attach the eager-load (e.g. on store/
            // update response paths where the section isn't rendered
            // and one extra query is wasteful). The detail page's
            // "Consuming Leave Requests" section iterates this list
            // directly; each item links to /hrm/leave-requests/{id}.
            'consuming_leave_requests' => $this->mapConsumingLeaveRequests(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }

    /**
     * Map the eager-loaded consuming LRs to the brief shape the
     * frontend's "Consuming Leave Requests" section consumes.
     *
     * Typed locally as Collection<int, LeaveRequest> via the
     * attribute read; PHPStan otherwise infers `mixed` from
     * `$this->consuming_leave_requests` because the attribute is
     * set dynamically via setAttribute() in the controller (it's
     * not a stored column or declared @property on the model).
     *
     * @return array<int, array<string, mixed>>
     */
    private function mapConsumingLeaveRequests(): array
    {
        $raw = $this->resource->getAttribute('consuming_leave_requests');
        if (! $raw instanceof Collection) {
            return [];
        }

        /** @var Collection<int, LeaveRequest> $consuming */
        $consuming = $raw;

        return array_values($consuming
            ->map(fn (LeaveRequest $lr): array => [
                'id' => $lr->id,
                'start_date' => $lr->start_date->toDateString(),
                'end_date' => $lr->end_date->toDateString(),
                'day_part' => $lr->day_part->value,
                'days_count' => (float) $lr->days_count,
                'approved_at' => $lr->approved_at?->toIso8601String(),
            ])
            ->all());
    }
}
