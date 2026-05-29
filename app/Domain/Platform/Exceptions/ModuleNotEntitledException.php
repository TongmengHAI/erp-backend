<?php

declare(strict_types=1);

namespace App\Domain\Platform\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Thrown by EnforceModuleEntitlement middleware when a tenant tries to
 * reach a module they don't have an Active entitlement for.
 *
 * HTTP 403 with error_code='module_not_entitled' + the module key, so the
 * SPA can route to a "module disabled" screen distinct from generic 403s.
 * The module key lets the SPA show the specific module name in the error
 * copy ("HRM is disabled for your organisation.").
 *
 * NOT thrown for super_admins — they bypass entitlement enforcement
 * entirely (same shape as TenantScope/CompanyScope/ResolveTenant
 * bypasses).
 */
final class ModuleNotEntitledException extends HttpException
{
    public readonly string $errorCode;

    public function __construct(
        public readonly string $moduleKey,
        string $message = 'The requested module is not entitled for the current tenant.',
        string $errorCode = 'module_not_entitled',
    ) {
        $this->errorCode = $errorCode;

        parent::__construct(
            statusCode: 403,
            message: $message,
        );
    }
}
