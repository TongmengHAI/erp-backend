<?php

declare(strict_types=1);

namespace App\Support\Audit\Services;

use App\Support\Audit\AuditContext;
use App\Support\Audit\Exceptions\AuditWriteFailedException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Encapsulates the INSERT into audit_logs.
 *
 * Synchronous + inside the parent transaction (§G — never silently drop).
 * Failure throws AuditWriteFailedException; the parent transaction rolls
 * back, taking both the business write and any partial audit rows with it.
 *
 * Bypasses Eloquent on purpose:
 *   1. Avoids recursive `creating`/`created` events on AuditLog
 *   2. Stays inside the caller's open transaction without ceremony
 *   3. Lets the DB-level CHECK constraint on `action` do its work
 *
 * Tenant resolution priority:
 *   1. $model->getAttribute('tenant_id')       — the model carries it (e.g. User)
 *   2. app(TenantContext::class)->current()?->id — fallback to request context
 *   3. null                                     — system action, no scope
 */
final class AuditWriter
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public static function record(
        Model $model,
        string $action,
        ?array $before,
        ?array $after,
    ): void {
        $context = app(AuditContext::class);

        /** @var int|null $modelTenantId */
        $modelTenantId = $model->getAttribute('tenant_id');
        $tenantId = $modelTenantId ?? app(TenantContext::class)->current()?->id;

        $row = [
            'tenant_id' => $tenantId,
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
            'action' => $action,
            'actor_type' => $context->actorType,
            'actor_id' => $context->actorId,
            'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'ip' => $context->ip,
            'user_agent' => $context->userAgent,
            'request_id' => $context->requestId,
            'created_at' => now(),
        ];

        try {
            DB::table('audit_logs')->insert($row);
        } catch (Throwable $e) {
            throw new AuditWriteFailedException(
                auditedModel: $model,
                action: $action,
                previous: $e,
            );
        }
    }
}
