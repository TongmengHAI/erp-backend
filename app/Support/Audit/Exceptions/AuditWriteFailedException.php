<?php

declare(strict_types=1);

namespace App\Support\Audit\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Throwable;

/**
 * Thrown when the INSERT into audit_logs fails for any reason. Because audit
 * writes are synchronous and inside the parent transaction (§G — no silent
 * fallbacks), this exception bubbles up and rolls back the entire business
 * write along with it. Loud, atomic, never silently dropped.
 */
final class AuditWriteFailedException extends RuntimeException
{
    public function __construct(
        public readonly Model $auditedModel,
        public readonly string $action,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'Audit write failed for %s id=%s action=%s.',
                $auditedModel::class,
                $auditedModel->getKey() ?? '<unsaved>',
                $action,
            ),
            0,
            $previous,
        );
    }
}
