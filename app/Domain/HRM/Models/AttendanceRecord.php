<?php

declare(strict_types=1);

namespace App\Domain\HRM\Models;

use App\Domain\HRM\Enums\AttendanceStatus;
use App\Support\Audit\Concerns\Auditable;
use App\Support\Company\Concerns\BelongsToCompany;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\HRM\AttendanceRecordFactory;
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
 * @property Carbon $date
 * @property string|null $clock_in
 * @property string|null $clock_out
 * @property AttendanceStatus $status
 * @property string|null $notes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property Employee|null $employee
 */
class AttendanceRecord extends Model
{
    use Auditable;
    use BelongsToCompany;
    use BelongsToTenant;

    /** @use HasFactory<AttendanceRecordFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'company_id',
        'employee_id',
        'date',
        'clock_in',
        'clock_out',
        'status',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        // clock_in / clock_out are NOT cast as datetime — they're TIME
        // columns and Laravel's datetime cast would re-anchor them to
        // an arbitrary date (1970-01-01 typically). Keep them as raw
        // HH:MM:SS strings on the model so the API layer round-trips
        // them losslessly and the frontend's timeConversion util can
        // parse them directly.
        return [
            'date' => 'date',
            'status' => AttendanceStatus::class,
        ];
    }

    /**
     * The employee this record belongs to. Always non-null at the data
     * layer (NOT NULL FK + RESTRICT), but typed Employee|null because
     * belongsTo respects the parent's SoftDeletes scope — a soft-deleted
     * employee returns null here and the UI displays a "(deleted employee)"
     * placeholder. Same pattern as LeaveRequest.employee.
     *
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    protected static function newFactory(): AttendanceRecordFactory
    {
        return AttendanceRecordFactory::new();
    }
}
