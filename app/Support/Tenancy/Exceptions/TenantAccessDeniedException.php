<?php

declare(strict_types=1);

namespace App\Support\Tenancy\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Thrown when an authenticated user attempts to access a tenant they don't
 * have a valid relationship with — or when their resolvable tenant is
 * suspended/archived/missing.
 *
 * HTTP-aware (extends HttpException) so Laravel's exception handler renders
 * it as a 403 automatically.
 */
final class TenantAccessDeniedException extends HttpException
{
    public function __construct(string $reason = 'Tenant access denied.')
    {
        parent::__construct(
            statusCode: 403,
            message: $reason,
        );
    }
}
