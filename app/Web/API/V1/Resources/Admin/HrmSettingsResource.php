<?php

declare(strict_types=1);

namespace App\Web\API\V1\Resources\Admin;

use App\Domain\HRM\Models\HrmSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wire shape for HRM settings — single resource per company, not a
 * brief/full pair (the settings page is the only consumer and it
 * always wants every field).
 *
 * @mixin HrmSettings
 */
class HrmSettingsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'auto_generate_employee_code' => (bool) $this->auto_generate_employee_code,
            'employee_code_prefix' => $this->employee_code_prefix,
            'default_employee_status' => $this->default_employee_status->value,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
