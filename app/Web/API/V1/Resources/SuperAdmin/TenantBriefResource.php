<?php

declare(strict_types=1);

namespace App\Web\API\V1\Resources\SuperAdmin;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Tenant
 *
 * Compact Tenant shape — used in the SA-side list endpoint. Drops
 * legal_name + settings for payload efficiency. Includes status so
 * the SA list UI can render the Active/Suspended badge inline per
 * Q7's locked decision (show-all-with-status-badge + filter chip).
 */
class TenantBriefResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'country_code' => $this->country_code,
            'default_currency' => $this->default_currency,
            'functional_currency' => $this->functional_currency,
            'timezone' => $this->timezone,
            'status' => $this->status->value,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
