<?php

declare(strict_types=1);

namespace App\Web\API\V1\Requests\Admin;

use App\Domain\HRM\Enums\EmployeeStatus;
use App\Domain\HRM\Models\HrmSettings;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates input for PATCH /api/v1/admin/hrm/settings/{settings}.
 *
 * Field-level rules per field. The cross-field consistency check
 * (prefix required when auto-gen is on) is added via withValidator so
 * it fires regardless of which fields are in the payload:
 *
 *   • Patch toggles auto-gen ON without sending prefix → check the
 *     existing row's prefix; surface 422 errors.employee_code_prefix
 *     if NULL.
 *   • Patch sets prefix to NULL while auto-gen is currently ON → same
 *     422 from the other angle.
 *   • Both fields in the patch → both new values evaluated together.
 *
 * Defense in depth — this same rule lives at the DB layer
 * (hrm_settings_autogen_prefix_consistency_check) and the frontend
 * Zod refinement. Three places, one rule.
 */
class UpdateHrmSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'auto_generate_employee_code' => ['sometimes', 'boolean'],
            'employee_code_prefix' => [
                'sometimes',
                'nullable',
                'string',
                'max:8',
                // Alphabet constraint — uppercase, digits, hyphen,
                // underscore. Same regex as the DB CHECK.
                'regex:/^[A-Z0-9_-]+$/',
            ],
            'default_employee_status' => [
                'sometimes',
                'string',
                Rule::in(array_column(EmployeeStatus::cases(), 'value')),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // Resolve the effective post-patch state. PATCH semantics:
            // a field not in the payload retains its current value.
            // Route-bound HrmSettings carries the existing row.
            /** @var HrmSettings|null $settings */
            $settings = $this->route('settings');
            if ($settings === null) {
                // No route binding (e.g. validation called outside
                // request context, defensive). Skip — the DB CHECK
                // will catch a direct-Action bypass.
                return;
            }

            $effectiveAutoGen = $this->has('auto_generate_employee_code')
                ? $this->boolean('auto_generate_employee_code')
                : (bool) $settings->auto_generate_employee_code;

            $effectivePrefix = $this->has('employee_code_prefix')
                ? $this->input('employee_code_prefix')
                : $settings->employee_code_prefix;

            if ($effectiveAutoGen === true
                && ($effectivePrefix === null || $effectivePrefix === '')) {
                $v->errors()->add(
                    'employee_code_prefix',
                    'Employee code prefix is required when auto-generate is enabled.'
                );
            }
        });
    }
}
