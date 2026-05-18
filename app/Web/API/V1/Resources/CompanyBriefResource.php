<?php

declare(strict_types=1);

namespace App\Web\API\V1\Resources;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Brief-shape representation of a Company — id, slug, name, status. Used
 * in list contexts (e.g. /auth/me's `companies` array for the company
 * switcher) where the full payload would be wasted bytes. Full shape is
 * available via CompanyResource for the SPA's current_company.
 *
 * @mixin Company
 */
class CompanyBriefResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'status' => $this->status->value,
        ];
    }
}
