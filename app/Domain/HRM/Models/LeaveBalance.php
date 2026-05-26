<?php

declare(strict_types=1);

namespace App\Domain\HRM\Models;

use App\Domain\HRM\Enums\LeaveType;
use App\Support\Audit\Concerns\Auditable;
use App\Support\Company\Concerns\BelongsToCompany;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\HRM\LeaveBalanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $company_id
 * @property int $employee_id
 * @property LeaveType $leave_type
 * @property int $period_year
 * @property float $allocated_days
 * @property string|null $notes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property Employee|null $employee
 *
 * Computed-on-read attributes (populated by LeaveBalanceQueryService;
 * NOT stored columns). Present as raw attributes on rows produced by
 * the service's withConsumed() builder; the resource handles fallback
 * for rows fetched bare (e.g. unit-test factories, model::find()).
 * @property float|null $consumed_days
 * @property float|null $remaining_days
 */
class LeaveBalance extends Model
{
    use Auditable;
    use BelongsToCompany;
    use BelongsToTenant;

    /** @use HasFactory<LeaveBalanceFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'company_id',
        'employee_id',
        'leave_type',
        'period_year',
        'allocated_days',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'leave_type' => LeaveType::class,
            'period_year' => 'integer',
            // decimal:1 mirrors the column shape (decimal(5,1)) and the
            // days_count cast on LeaveRequest, so the SUM aggregate's
            // subtraction stays in a consistent numeric space.
            'allocated_days' => 'decimal:1',
        ];
    }

    /**
     * The employee this balance is for. Always non-null at the data
     * layer (NOT NULL FK); typed nullable in PHPDoc only because the
     * relation can return null if the related row is soft-deleted via
     * its own SoftDeletes scope.
     *
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    protected static function newFactory(): LeaveBalanceFactory
    {
        return LeaveBalanceFactory::new();
    }
}
