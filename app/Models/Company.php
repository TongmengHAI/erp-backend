<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Concerns\Auditable;
use App\Support\Company\Enums\CompanyStatus;
use App\Support\Company\Events\CompanyCreated;
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

    /**
     * Fire CompanyCreated when a Company row is inserted. Lets HRM,
     * Accounting, Inventory, and other domains bootstrap per-company
     * state (settings rows, default COA, etc.) via subscribed
     * listeners without the Company model importing any domain code.
     *
     * Eloquent's `created` event fires AFTER the row is persisted, so
     * `$company->id` is stable for the listener. Synchronous dispatch
     * — listeners run in the same request. Migrations and seeders
     *  fire this too (no withoutEvents in our seeder paths), which is
     * the right behavior: the seed run should produce the same
     * downstream state as a real Company creation would.
     */
    protected static function booted(): void
    {
        static::created(static function (Company $company): void {
            CompanyCreated::dispatch($company);
        });
    }
}
