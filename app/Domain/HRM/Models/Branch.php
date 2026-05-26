<?php

declare(strict_types=1);

namespace App\Domain\HRM\Models;

use App\Domain\HRM\Enums\BranchStatus;
use App\Support\Audit\Concerns\Auditable;
use App\Support\Company\Concerns\BelongsToCompany;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\HRM\BranchFactory;
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
 * @property string|null $address
 * @property string|null $city
 * @property string|null $country_code
 * @property string|null $phone
 * @property BranchStatus $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property int $employees_count Computed; eager-load via ->loadCount('employees')
 *                                or naive fallback runs a count subquery.
 */
class Branch extends Model
{
    use Auditable;
    use BelongsToCompany;
    use BelongsToTenant;

    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'company_id',
        'code',
        'name',
        'description',
        'address',
        'city',
        'country_code',
        'phone',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => BranchStatus::class,
        ];
    }

    /**
     * Employees currently assigned to this branch. Same scoping discipline
     * as Department.employees / Position.employees — relation runs through
     * Employee global scopes so cross-context FKs can never leak.
     *
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * Computed employees_count accessor — mirror of Department / Position.
     * BranchController::show pre-populates via ->loadCount('employees') so
     * this is a single attribute read on serialisation.
     *
     * @return Attribute<int, never>
     */
    protected function employeesCount(): Attribute
    {
        return Attribute::get(
            fn (): int => (int) ($this->attributes['employees_count']
                ?? $this->employees()->count()),
        );
    }

    protected static function newFactory(): BranchFactory
    {
        return BranchFactory::new();
    }
}
