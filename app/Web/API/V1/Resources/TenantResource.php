<?php

declare(strict_types=1);

namespace App\Web\API\V1\Resources;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Tenant
 */
class TenantResource extends JsonResource
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
        ];
    }
}
