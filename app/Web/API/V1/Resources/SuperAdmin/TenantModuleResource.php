<?php

declare(strict_types=1);

namespace App\Web\API\V1\Resources\SuperAdmin;

use App\Domain\Platform\Models\TenantModule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TenantModule
 */
class TenantModuleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'module_key' => $this->module_key,
            'status' => $this->status->value,
            'enabled_at' => $this->enabled_at?->toIso8601String(),
            'enabled_by_user_id' => $this->enabled_by_user_id,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
