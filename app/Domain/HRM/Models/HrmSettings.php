<?php

declare(strict_types=1);

namespace App\Domain\HRM\Models;

use App\Domain\HRM\Enums\EmployeeStatus;
use App\Support\Audit\Concerns\Auditable;
use App\Support\Company\Concerns\BelongsToCompany;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $company_id
 * @property bool $auto_generate_employee_code
 * @property string|null $employee_code_prefix
 * @property EmployeeStatus $default_employee_status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * Per-company HRM settings. One row per (tenant_id, company_id),
 * created lazily by BootstrapHrmSettingsListener on the
 * CompanyCreated event (and via migration backfill for existing
 * companies at deploy time).
 *
 * NO SoftDeletes — settings are 1:1 with Company; there's no
 * meaningful "restore my settings" flow for a deleted company.
 * NO HasFactory — created via listener, not factory; tests that
 * need a settings row use HrmSettings::query()->where(...)->update()
 * against the row the listener already created on Company creation.
 *
 * Three traits only: BelongsToTenant, BelongsToCompany, Auditable.
 * Standard 4-trait stack minus SoftDeletes.
 */
class HrmSettings extends Model
{
    use Auditable;
    use BelongsToCompany;
    use BelongsToTenant;

    protected $table = 'hrm_settings';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'auto_generate_employee_code',
        'employee_code_prefix',
        'default_employee_status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'auto_generate_employee_code' => 'boolean',
            'default_employee_status' => EmployeeStatus::class,
        ];
    }
}
