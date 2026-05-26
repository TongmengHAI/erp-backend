<?php

declare(strict_types=1);

namespace App\Web\API\V1\Requests\HRM;

use App\Domain\HRM\Enums\PositionStatus;
use App\Domain\HRM\Models\Position;
use App\Support\Company\CompanyContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/v1/hrm/positions/{position} input. Mirror of
 * UpdateDepartmentRequest with the standard `sometimes` modifier
 * and ignore-self on the scoped uniqueness.
 */
class UpdatePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->current()?->id;
        $companyId = app(CompanyContext::class)->current()?->id;

        /** @var Position|null $position */
        $position = $this->route('position');
        $positionId = $position?->id;

        return [
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:32',
                Rule::unique('positions', 'code')
                    ->ignore($positionId)
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)
                        ->where('company_id', $companyId)
                        ->whereNull('deleted_at')),
            ],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'status' => ['sometimes', 'required', 'string', Rule::in(array_column(PositionStatus::cases(), 'value'))],
        ];
    }
}
