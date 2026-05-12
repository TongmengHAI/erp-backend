<?php

declare(strict_types=1);

namespace App\Support\Tenancy\Exceptions;

use RuntimeException;

/**
 * Thrown when tenant-scoped code runs without a resolved tenant.
 *
 * Per §G, the rule is fail loud — a missing tenant must NEVER silently fall back
 * to "all rows" or "no filter". Silent fallback in tenancy is invisible data leakage.
 */
final class TenantContextMissingException extends RuntimeException
{
    public function __construct(string $context = '')
    {
        $message = 'No tenant context resolved. '
            .'Tenant must be set at the request boundary (or via TenantContext::asSystem '
            .'in console/seeder code) before tenant-scoped queries or writes can run.';

        if ($context !== '') {
            $message .= ' Context: '.$context;
        }

        parent::__construct($message);
    }
}
