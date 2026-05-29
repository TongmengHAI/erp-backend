<?php

declare(strict_types=1);

namespace App\Web\API\V1\Requests\SuperAdmin;

use App\Domain\Platform\Enums\ModuleStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/v1/super-admin/tenants/{tenant}/modules
 *
 * Request shape:
 *   {
 *     "modules": [
 *       { "module_key": "hrm", "status": "active" },
 *       ...
 *     ]
 *   }
 *
 * Authorisation is handled upstream by the 'super_admin' middleware
 * (404 for non-SA). Field validation here:
 *   - modules array required, non-empty
 *   - each module_key in the known-modules allowlist
 *   - each status in the ModuleStatus enum values
 *
 * App-layer allowlist mirrors EnforceModuleEntitlement::KNOWN_MODULES —
 * both keep ['hrm'] as the v1 set. Adding a module ships an entry in
 * both places AND in the frontend's LAUNCHER_APPS registry.
 */
final class SyncTenantModulesRequest extends FormRequest
{
    /** @var list<string> */
    private const KNOWN_MODULES = ['hrm'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $statusValues = array_column(ModuleStatus::cases(), 'value');

        return [
            'modules' => ['required', 'array', 'min:1'],
            'modules.*.module_key' => ['required', 'string', Rule::in(self::KNOWN_MODULES)],
            'modules.*.status' => ['required', 'string', Rule::in($statusValues)],
        ];
    }
}
