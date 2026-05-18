<?php

declare(strict_types=1);

namespace App\Support\Audit\Services;

use App\Support\Audit\AuditContext;
use App\Support\Audit\Exceptions\AuditWriteFailedException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Encapsulates the INSERT into audit_logs.
 *
 * Synchronous + inside the parent transaction (§G — never silently drop).
 * Failure throws AuditWriteFailedException; the parent transaction rolls
 * back, taking both the business write and any partial audit rows with it.
 *
 * Resolution at write time:
 *   actor      → Auth::user() — resolved NOW so that actingAs() / login()
 *                that happens between scoped binding creation and this
 *                write are captured correctly.
 *   ip/UA      → AuditContext (scoped, stable per request)
 *   request_id → AuditContext (stable per request, used for correlation)
 *   tenant_id  → $model->getAttribute('tenant_id') first, then
 *                TenantContext::current()?->id, finally null.
 *   company_id → $model->getAttribute('company_id') only — does NOT fall back
 *                to CompanyContext. Tenant-only models (Tenant, Company itself,
 *                User identity rows) genuinely have no company dimension, so
 *                their audit rows must record NULL even when a company context
 *                happens to be set on the request. The model attribute is the
 *                authoritative answer.
 *
 * Bypasses Eloquent for the INSERT to avoid recursive `created` events on
 * AuditLog and to stay inside the caller's open transaction without ceremony.
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

        /** @var Authenticatable|null $user */
        $user = Auth::user();

        /** @var int|null $modelTenantId */
        $modelTenantId = $model->getAttribute('tenant_id');
        $tenantId = $modelTenantId ?? app(TenantContext::class)->current()?->id;

        // company_id reads off the model only — no CompanyContext fallback.
        // Tenant-only models (e.g. User, Tenant, Company) don't carry a
        // company_id attribute; getAttribute() returns null and we record
        // null. Company-scoped models (via BelongsToCompany) auto-fill
        // company_id at boot so the attribute is always set by the time
        // the audit write fires.
        /** @var int|null $companyId */
        $companyId = $model->getAttribute('company_id');

        $row = [
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
            'action' => $action,
            'actor_type' => $user !== null ? $user::class : null,
            'actor_id' => $user?->getAuthIdentifier() === null ? null : (int) $user->getAuthIdentifier(),
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
