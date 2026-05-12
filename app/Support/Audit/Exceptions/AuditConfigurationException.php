<?php

declare(strict_types=1);

namespace App\Support\Audit\Exceptions;

use LogicException;

/**
 * Thrown at class-boot time when a model's audit configuration is ambiguous —
 * specifically when a model declares both `$auditOnly` (allowlist) and
 * `$auditExcept` (denylist). The two are mutually exclusive by design.
 *
 * Fails loud per §G — same principle as financial code: audit ambiguity
 * must surface immediately, not be silently resolved at write time.
 */
final class AuditConfigurationException extends LogicException
{
    public function __construct(string $modelClass)
    {
        parent::__construct(sprintf(
            'Model %s declares both $auditOnly (allowlist) and $auditExcept (denylist). '
            .'These are mutually exclusive — pick one and remove the other. '
            .'$auditOnly is for tight control on sensitive entities (only these fields are audited); '
            .'$auditExcept is for excluding noisy columns on otherwise-fully-audited tables.',
            $modelClass
        ));
    }
}
