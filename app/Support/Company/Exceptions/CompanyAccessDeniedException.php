<?php

declare(strict_types=1);

namespace App\Support\Company\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Thrown when a request specifies a company (via X-Company-Id header or
 * user.current_company_id) that the user can't access in the current
 * tenant — either the company doesn't exist, doesn't belong to the
 * resolved tenant, or is archived.
 *
 * HTTP 403. Distinguished from CompanyContextMissingException (401 with
 * company_required): missing means "you haven't chosen a company yet,
 * pick one"; access-denied means "you tried to use this specific company
 * and you can't."
 */
final class CompanyAccessDeniedException extends HttpException
{
    public function __construct(string $reason = 'Company access denied.')
    {
        parent::__construct(
            statusCode: 403,
            message: $reason,
        );
    }
}
