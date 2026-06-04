<?php

declare(strict_types=1);

namespace App\Web\API\V1\Resources\SuperAdmin;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Tenant
 *
 * Full Tenant shape — used by the SA-side show + store + update
 * endpoints. Adds legal_name and timestamps over TenantBriefResource.
 */
class TenantFullResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'country_code' => $this->country_code,
            'default_currency' => $this->default_currency,
            'functional_currency' => $this->functional_currency,
            'timezone' => $this->timezone,
            'status' => $this->status->value,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
