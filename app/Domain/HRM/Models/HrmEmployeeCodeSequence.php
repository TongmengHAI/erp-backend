<?php

declare(strict_types=1);

namespace App\Domain\HRM\Models;

use App\Support\Company\Concerns\BelongsToCompany;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $company_id
 * @property int $next_value
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * Counter row for auto-generated employee codes. Pure state — no
 * Auditable trait (would log noise on every Employee create) and no
 * SoftDeletes (no meaningful restore flow for a counter).
 *
 * Lazy-created: starts empty, populated by EmployeeCodeGenerator's
 * `firstOrCreate` on first auto-gen use. Companies that never enable
 * auto-gen never get a row.
 *
 * Two traits only: BelongsToTenant, BelongsToCompany. Standard
 * scoping — `next_value` is read under tenant + company scope at
 * generation time.
 */
class HrmEmployeeCodeSequence extends Model
{
    use BelongsToCompany;
    use BelongsToTenant;

    protected $table = 'hrm_employee_code_sequences';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'next_value',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'next_value' => 'integer',
        ];
    }
}
