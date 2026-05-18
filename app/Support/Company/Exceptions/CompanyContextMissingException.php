<?php

declare(strict_types=1);

namespace App\Support\Company\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Thrown when company-scoped code runs without a resolved company.
 *
 * Two distinct cases:
 *  1) Inside a request, ResolveCompany middleware exhausted its 5-branch
 *     resolution chain (header → current_company_id → default_company_id →
 *     sole-company fallback → none) and the route did not opt out via
 *     `meta.companyOptional = true`. HTTP 401 with error_code='company_required'
 *     so the SPA can route the user to a company-picker UI.
 *  2) Inside seeders/console commands/admin code, company-scoped queries or
 *     writes attempted without a company set AND without an explicit
 *     CompanyContext::acrossCompanies() wrap. Per CLAUDE.md §G the rule is
 *     fail loud — silent fallback is invisible cross-company data leakage.
 *
 * The HTTP-aware shape (extends HttpException) lets Laravel render case 1
 * as a proper 401. For case 2 the message contains useful diagnostic
 * context for the developer.
 */
final class CompanyContextMissingException extends HttpException
{
    public readonly string $errorCode;

    public function __construct(
        string $message = 'No company context resolved.',
        string $errorCode = 'company_required',
    ) {
        $this->errorCode = $errorCode;

        parent::__construct(
            statusCode: 401,
            message: $message,
        );
    }

    /**
     * Variant used inside the data layer (CompanyScope, BelongsToCompany)
     * when no request context exists — adds a developer-oriented hint.
     */
    public static function forQuery(string $modelClass): self
    {
        return new self(
            sprintf(
                'No company context resolved. Company must be set at the request '
                .'boundary (or via CompanyContext::acrossCompanies in console/seeder code) '
                .'before company-scoped queries or writes can run. Context: Querying %s without a resolved company.',
                $modelClass,
            ),
        );
    }
}
