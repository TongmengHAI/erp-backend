<?php

declare(strict_types=1);

namespace App\Web\API\V1\Controllers\Admin;

use App\Domain\HRM\Actions\UpdateHrmSettingsAction;
use App\Domain\HRM\Models\HrmSettings;
use App\Support\Company\CompanyContext;
use App\Web\API\V1\Controllers\Concerns\AuthorizesAdminAccess;
use App\Web\API\V1\Controllers\Controller;
use App\Web\API\V1\Requests\Admin\UpdateHrmSettingsRequest;
use App\Web\API\V1\Resources\Admin\HrmSettingsResource;
use Illuminate\Http\Request;

/**
 * Single-resource admin controller for HRM settings.
 *
 * NOT an apiResource — settings is one row per company, not a
 * collection. Two endpoints:
 *
 *   GET   /api/v1/admin/hrm/settings?company_id=X — show. Resolves the
 *         settings row for the given company (defaults to current
 *         company when no query param).
 *   PATCH /api/v1/admin/hrm/settings/{settings}   — update. Route-bound
 *         to the row; the UpdateHrmSettingsRequest's withValidator
 *         consults the bound row for the cross-field consistency
 *         check.
 *
 * Both endpoints rely on BelongsToTenant + BelongsToCompany global
 * scopes for cross-tenant/cross-company isolation — a query param
 * pointing at another tenant's company returns 404 via the scoped
 * query, never leaks data.
 */
class HrmSettingsController extends Controller
{
    use AuthorizesAdminAccess;

    public function show(Request $request): HrmSettingsResource
    {
        $this->authorizeAdmin($request, 'settings.hrm.view');

        $validated = $request->validate([
            'company_id' => ['sometimes', 'integer', 'min:1'],
        ]);

        // Default to the current company when no query param; otherwise
        // explicit company_id. Both are tenant+company-scoped via the
        // model's global scopes, so a cross-tenant or cross-company id
        // returns 404 via firstOrFail rather than leaking the row.
        $query = HrmSettings::query();
        if (isset($validated['company_id'])) {
            $query->where('company_id', (int) $validated['company_id']);
        } else {
            // Current-company default. The CompanyContext is set by the
            // `company` middleware on the protected route group.
            $query->where('company_id', app(CompanyContext::class)->currentId());
        }

        $settings = $query->firstOrFail();

        return new HrmSettingsResource($settings);
    }

    public function update(
        UpdateHrmSettingsRequest $request,
        HrmSettings $settings,
        UpdateHrmSettingsAction $action,
    ): HrmSettingsResource {
        $this->authorizeAdmin($request, 'settings.hrm.update');

        /** @var array{auto_generate_employee_code?: bool, employee_code_prefix?: string|null, default_employee_status?: string} $data */
        $data = $request->validated();
        $settings = $action->execute($settings, $data);

        return new HrmSettingsResource($settings);
    }
}
