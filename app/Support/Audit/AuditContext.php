<?php

declare(strict_types=1);

namespace App\Support\Audit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Request-scoped snapshot of WHO + WHERE-FROM is performing the audited
 * operation. Bound as scoped() in AppServiceProvider — one instance per
 * request, lazily resolved on first access.
 *
 * Notably DOES NOT include tenant_id. That comes from the audited model
 * itself (or TenantContext as fallback) at write time — see AuditWriter
 * for the resolution logic.
 */
final readonly class AuditContext
{
    public function __construct(
        public ?int $actorId,
        public ?string $actorType,
        public ?string $ip,
        public ?string $userAgent,
        public string $requestId,
    ) {}

    /**
     * Build a context from the currently authenticated user + Request facade.
     * Used by AppServiceProvider's scoped binding for HTTP requests.
     */
    public static function fromCurrentRequest(): self
    {
        $user = Auth::user();
        $request = app(Request::class);

        return new self(
            actorId: $user?->getAuthIdentifier() === null ? null : (int) $user->getAuthIdentifier(),
            actorType: $user !== null ? $user::class : null,
            ip: $request->ip(),
            userAgent: $request->userAgent() === null ? null : Str::substr($request->userAgent(), 0, 500),
            requestId: $request->headers->get('X-Request-ID') ?? Str::uuid()->toString(),
        );
    }

    /**
     * Context for non-request operations: seeders, queued jobs, console
     * commands. actor_type='system' on the resulting audit row.
     */
    public static function asSystem(): self
    {
        return new self(
            actorId: null,
            actorType: 'system',
            ip: null,
            userAgent: null,
            requestId: Str::uuid()->toString(),
        );
    }
}
