<?php

declare(strict_types=1);

namespace App\Support\Audit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Read-only Eloquent model for audit_logs.
 *
 * The DB-level trigger on audit_logs is the real guard against UPDATE/DELETE
 * (defense in depth). This model adds an Eloquent-level rejection of
 * `creating`, `updating`, and `deleting` events so developers get a clear
 * exception at write-time rather than a cryptic PG trigger error if they
 * try `AuditLog::create([...])` or `$log->save()` directly.
 *
 * The ONLY way to add a row is through AuditWriter::record() which goes
 * through DB::table() and bypasses these Eloquent events on purpose.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int|null $company_id
 * @property string $auditable_type
 * @property int $auditable_id
 * @property string $action
 * @property string|null $actor_type
 * @property int|null $actor_id
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property Carbon $created_at
 */
final class AuditLog extends Model
{
    protected $table = 'audit_logs';

    // No updated_at column; created_at is set explicitly by AuditWriter.
    public $timestamps = false;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        $appendOnly = 'audit_logs is append-only. Use AuditWriter::record() to add rows; UPDATE and DELETE are forbidden.';

        self::creating(fn () => throw new LogicException(
            'AuditLog::create() / $log->save() is forbidden — go through AuditWriter::record().'
        ));
        self::updating(fn () => throw new LogicException($appendOnly));
        self::deleting(fn () => throw new LogicException($appendOnly));
    }
}
