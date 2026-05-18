<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Support\Audit\Concerns\Auditable;
use App\Support\Company\Concerns\BelongsToCompany;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Throwaway model exercising the realistic Phase M shape: tenant-scoped,
 * company-scoped, and audited. Used by AuditableCompanyTest to verify that
 * audit rows capture company_id correctly.
 *
 * Backing table `audit_company_test_widgets` is created in the test's
 * beforeEach via Schema::create and rolled back by RefreshDatabase.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int|null $company_id
 * @property string $name
 */
class AuditCompanyTestWidget extends Model
{
    use Auditable;
    use BelongsToCompany;
    use BelongsToTenant;

    protected $table = 'audit_company_test_widgets';

    protected $guarded = [];
}
