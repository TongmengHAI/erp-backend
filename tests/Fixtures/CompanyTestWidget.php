<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Support\Company\Concerns\BelongsToCompany;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Throwaway model used by company unit tests. Exercises both BelongsToTenant
 * AND BelongsToCompany — the realistic shape for every Phase M business
 * model (employees, journal entries, warehouses, POs, etc.).
 *
 * Backing table `company_test_widgets` is created in each test's beforeEach
 * via Schema::create and rolled back via RefreshDatabase or afterEach drop.
 * Not part of production.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int|null $company_id
 * @property string $name
 */
class CompanyTestWidget extends Model
{
    use BelongsToCompany;
    use BelongsToTenant;

    protected $table = 'company_test_widgets';

    protected $guarded = [];
}
