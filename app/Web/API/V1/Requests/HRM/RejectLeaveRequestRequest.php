<?php

declare(strict_types=1);

namespace App\Web\API\V1\Requests\HRM;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/hrm/leave-requests/{leaveRequest}/reject input.
 *
 * Mirror of ApproveLeaveRequestRequest. Note is technically nullable at
 * this layer for shape symmetry; the SPA may surface it as a soft
 * "please give a reason" prompt for rejections specifically (UX policy,
 * not a schema rule).
 */
class RejectLeaveRequestRequest extends FormRequest
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
