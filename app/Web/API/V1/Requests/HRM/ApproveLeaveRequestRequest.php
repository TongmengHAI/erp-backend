<?php

declare(strict_types=1);

namespace App\Web\API\V1\Requests\HRM;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/hrm/leave-requests/{leaveRequest}/approve input.
 *
 * One field: an optional note. The transition itself carries no other
 * inputs — the request id and approver identity are derived from the
 * URL and the authenticated session.
 *
 * Note is optional for approvals (the common case is "approved, no
 * comment"). Rejection is the mirror request and has the same shape.
 */
class ApproveLeaveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
