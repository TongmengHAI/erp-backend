<?php

declare(strict_types=1);

namespace App\Domain\HRM\Models;

use App\Domain\HRM\Enums\PositionStatus;
use App\Support\Audit\Concerns\Auditable;
use App\Support\Company\Concerns\BelongsToCompany;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\HRM\PositionFactory;
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
 * @property string $title
 * @property string|null $description
 * @property PositionStatus $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property int $employees_count Computed; eager-load via ->loadCount('employees')
 *                                or naive fallback runs a count subquery.
 */
class Position extends Model
{
    use Auditable;
    use BelongsToCompany;
    use BelongsToTenant;

    /** @use HasFactory<PositionFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'company_id',
        'code',
        'title',
        'description',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PositionStatus::class,
        ];
    }

    /**
     * Employees currently holding this position. Same scoping discipline
     * as Department.employees — relation runs through Employee global
     * scopes so cross-context FKs can never leak.
     *
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * Computed `employees_count` accessor — mirror of Department's.
     * Eager-loaded via ->loadCount('employees') in PositionController::show;
     * naive fallback runs a subquery for unprepared callers.
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

    protected static function newFactory(): PositionFactory
    {
        return PositionFactory::new();
    }
}
