<?php

declare(strict_types=1);

namespace App\Domain\HRM\Models;

use App\Domain\HRM\Enums\EmployeeStatus;
use App\Support\Audit\Concerns\Auditable;
use App\Support\Company\Concerns\BelongsToCompany;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\HRM\EmployeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $company_id
 * @property string $employee_code
 * @property string $full_name
 * @property string|null $email
 * @property string|null $job_title
 * @property int|null $department_id
 * @property Carbon $hire_date
 * @property EmployeeStatus $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property Department|null $department
 */
class Employee extends Model
{
    use Auditable;
    use BelongsToCompany;
    use BelongsToTenant;

    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'company_id',
        'employee_code',
        'full_name',
        'email',
        'job_title',
        'department_id',
        'hire_date',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'hire_date' => 'date',
            'status' => EmployeeStatus::class,
        ];
    }

    /**
     * The employee's current department within the same (tenant, company).
     * Nullable — see migration notes on the FK. belongsTo respects
     * SoftDeletes on the parent, so a soft-deleted department returns
     * null here and the UI displays "—".
     *
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    protected static function newFactory(): EmployeeFactory
    {
        return EmployeeFactory::new();
    }
}
