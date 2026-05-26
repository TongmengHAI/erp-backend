<?php

declare(strict_types=1);

namespace App\Web\API\V1\Requests\HRM;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates input for PATCH /api/v1/hrm/leave-balances/{leaveBalance}.
 *
 * Only allocated_days + notes are editable. Changing the identity tuple
 * (employee_id + leave_type + period_year) would conceptually create a
 * different row — those fields aren't accepted here. The user wanting
 * to "move" a balance creates a new row and deletes the old.
 *
 * Mirror of UpdateBranchRequest's sometimes-everywhere pattern.
 */
class UpdateLeaveBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'allocated_days' => ['sometimes', 'required', 'numeric', 'min:0', 'max:366', 'multiple_of:0.5'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
