<?php

declare(strict_types=1);

namespace App\Web\API\V1\Resources;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full-shape representation of a Company. Mirrors TenantResource's
 * structure — used in /auth/me's current_company field where the SPA
 * needs every property to render headers, set currency-formatting
 * defaults, and gate UI by company-level settings.
 *
 * @mixin Company
 */
class CompanyResource extends JsonResource
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
        ];
    }
}
