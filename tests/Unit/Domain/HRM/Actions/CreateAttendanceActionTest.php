<?php

declare(strict_types=1);

use App\Domain\HRM\Actions\CreateAttendanceAction;
use App\Domain\HRM\Enums\AttendanceStatus;
use App\Domain\HRM\Models\AttendanceRecord;
use App\Domain\HRM\Models\Employee;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\Models\AuditLog;
use App\Support\Company\CompanyContext;
use App\Support\Tenancy\TenantContext;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->company = Company::factory()->forTenant($this->tenant)->create();
    app(TenantContext::class)->setCurrent($this->tenant);
    app(CompanyContext::class)->setCurrent($this->company);

    $this->employee = Employee::factory()->forCompany($this->company)->create();
});

it('creates an attendance record with auto-filled tenant_id and company_id', function (): void {
    $record = app(CreateAttendanceAction::class)->execute([
        'employee_id' => $this->employee->id,
        'date' => '2026-05-14',
        'clock_in' => '09:00:00',
        'clock_out' => '18:00:00',
        'status' => AttendanceStatus::Present->value,
        'notes' => null,
    ]);

    expect($record->id)->not->toBeNull();
    expect($record->tenant_id)->toBe($this->tenant->id);
    expect($record->company_id)->toBe($this->company->id);
    expect($record->employee_id)->toBe($this->employee->id);
    expect($record->status)->toBe(AttendanceStatus::Present);
    expect($record->clock_in)->toBe('09:00:00');
    expect($record->clock_out)->toBe('18:00:00');
});

it('creates an absent record with null clock times', function (): void {
    $record = app(CreateAttendanceAction::class)->execute([
        'employee_id' => $this->employee->id,
        'date' => '2026-05-15',
        'clock_in' => null,
        'clock_out' => null,
        'status' => AttendanceStatus::Absent->value,
        'notes' => 'No-show.',
    ]);

    expect($record->status)->toBe(AttendanceStatus::Absent);
    expect($record->clock_in)->toBeNull();
    expect($record->clock_out)->toBeNull();
});

it('writes an audit row with non-null tenant_id and company_id on create', function (): void {
    $record = app(CreateAttendanceAction::class)->execute([
        'employee_id' => $this->employee->id,
        'date' => '2026-05-16',
        'clock_in' => '09:00:00',
        'clock_out' => '18:00:00',
        'status' => AttendanceStatus::Present->value,
    ]);

    $row = AuditLog::query()
        ->where('auditable_type', AttendanceRecord::class)
        ->where('auditable_id', $record->id)
        ->where('action', 'created')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->tenant_id)->toBe($this->tenant->id);
    expect($row->company_id)->toBe($this->company->id);
});

it('captures the authenticated user as actor_id in the audit row', function (): void {
    $user = User::factory()->forTenant($this->tenant)->create();
    $this->actingAs($user);

    $record = app(CreateAttendanceAction::class)->execute([
        'employee_id' => $this->employee->id,
        'date' => '2026-05-17',
        'clock_in' => '09:00:00',
        'clock_out' => '18:00:00',
        'status' => AttendanceStatus::Present->value,
    ]);

    $row = AuditLog::query()
        ->where('auditable_type', AttendanceRecord::class)
        ->where('auditable_id', $record->id)
        ->first();

    expect($row->actor_id)->toBe($user->id);
});
