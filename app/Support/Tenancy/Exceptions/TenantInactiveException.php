<?php

declare(strict_types=1);

namespace App\Support\Tenancy\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Thrown by ResolveTenant when the user's resolved tenant exists but is not Active
 * (status = suspended | archived). HTTP 401 with error_code=tenant_inactive so the
 * frontend can distinguish "your session/tenant is dead" from "you're not signed in"
 * and route the user to a tenant-suspended page rather than the login page.
 *
 * NOT a 403 — a 403 implies "you're authenticated but not permitted to do this thing,"
 * which doesn't fit. Inactive tenant means the entire workspace is unreachable;
 * 401-with-code matches the auth-flow semantics expected by the SPA.
 */
final class TenantInactiveException extends HttpException
{
    public readonly string $errorCode;

    public function __construct(
        string $message = 'The current tenant is not active.',
        string $errorCode = 'tenant_inactive',
    ) {
        $this->errorCode = $errorCode;

        parent::__construct(
            statusCode: 401,
            message: $message,
        );
    }
}
