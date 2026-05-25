<?php

declare(strict_types=1);

namespace App\Domain\HRM\Models;

use App\Domain\HRM\Enums\DepartmentStatus;
use App\Support\Audit\Concerns\Auditable;
use App\Support\Company\Concerns\BelongsToCompany;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\HRM\DepartmentFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $company_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property DepartmentStatus $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property int $employees_count Computed; eager-load via ->loadCount('employees')
 *                                or naive fallback runs a count subquery.
 */
class Department extends Model
{
    use Auditable;
    use BelongsToCompany;
    use BelongsToTenant;

    /** @use HasFactory<DepartmentFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'company_id',
        'code',
        'name',
        'description',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => DepartmentStatus::class,
        ];
    }

    /**
     * Employees currently assigned to this department. The relation is
     * scoped through the Employee global scopes (TenantScope +
     * CompanyScope), so a department in one company never returns
     * employees from another even if a future bug were to mis-route an
     * FK.
     *
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * Computed `employees_count` accessor for DepartmentResource. Three
     * paths:
     *  - Eager-loaded via `Department::withCount('employees')` or
     *    `$department->loadCount('employees')` — fastest, one extra
     *    subquery on the parent fetch. This is what the controller's
     *    show() path uses, so the resource projection is a single
     *    attribute read.
     *  - Naive fallback: a per-call count() subquery. Used by callers
     *    who haven't pre-counted (rare; only `DepartmentController::show`
     *    consumes this accessor today).
     *
     * Defining as Attribute (vs an `get…Attribute` magic method) keeps
     * the method signature explicit and PHPStan-friendly.
     *
     * @return Attribute<int, never>
     */
    protected function employeesCount(): Attribute
    {
        return Attribute::get(
            // The attributes bag carries `employees_count` when withCount()
            // ran; otherwise count() executes against the relation builder.
            fn (): int => (int) ($this->attributes['employees_count']
                ?? $this->employees()->count()),
        );
    }

    protected static function newFactory(): DepartmentFactory
    {
        return DepartmentFactory::new();
    }
}
