<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Concerns\Auditable;
use App\Support\Company\Enums\CompanyStatus;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $slug
 * @property string $name
 * @property string|null $legal_name
 * @property string $country_code
 * @property string $default_currency
 * @property string $functional_currency
 * @property string $timezone
 * @property CompanyStatus $status
 * @property array<string, mixed>|null $settings
 *
 * Identity-source model — uses BelongsToTenant (a company belongs to one
 * tenant) but is NOT scoped by BelongsToCompany. Companies DEFINE the
 * company scope; scoping them at the company layer would create the same
 * circular dependency the User/BelongsToTenant fix (commit c519e10)
 * resolved one layer up. Documented in CLAUDE.md §3.
 */
class Company extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'slug',
        'name',
        'legal_name',
        'country_code',
        'default_currency',
        'functional_currency',
        'timezone',
        'status',
        'settings',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
            'settings' => 'array',
        ];
    }
}
