<?php

declare(strict_types=1);

namespace App\Domain\HRM\Models;

use App\Domain\HRM\Enums\DayPart;
use App\Domain\HRM\Enums\LeaveRequestStatus;
use App\Domain\HRM\Enums\LeaveType;
use App\Models\User;
use App\Support\Audit\Concerns\Auditable;
use App\Support\Company\Concerns\BelongsToCompany;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\HRM\LeaveRequestFactory;
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
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property DayPart $day_part
 * @property string|null $reason
 * @property LeaveRequestStatus $status
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $approver_note
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property Employee|null $employee
 * @property User|null $approver
 */
class LeaveRequest extends Model
{
    use Auditable;
    use BelongsToCompany;
    use BelongsToTenant;

    /** @use HasFactory<LeaveRequestFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'company_id',
        'employee_id',
        'leave_type',
        'start_date',
        'end_date',
        'day_part',
        'reason',
        'status',
        'approved_by',
        'approved_at',
        'approver_note',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'leave_type' => LeaveType::class,
            'day_part' => DayPart::class,
            'status' => LeaveRequestStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * The employee this request is for. Always non-null at the data
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

    /**
     * The user who approved or rejected this request. Null when the
     * request is still pending, AND when the original approver's user
     * row was hard-deleted (ON DELETE SET NULL — the decision is
     * preserved as status + approved_at, just loses the actor name).
     *
     * Named `approver` not `approvedBy` because the relation describes
     * the related thing, not the FK column. The FK column is
     * approved_by; the related model is the approver.
     *
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    protected static function newFactory(): LeaveRequestFactory
    {
        return LeaveRequestFactory::new();
    }
}
