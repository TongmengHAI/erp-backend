<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Support\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Fixture for the boot-time config-check test. Overrides BOTH auditOnly() AND
 * auditExcept() — the trait must refuse this at boot via AuditConfigurationException.
 *
 * Used in EXACTLY ONE test. Touching this class triggers bootAuditable which
 * throws — and after a class boot throws, the class is in an undefined state
 * and shouldn't be re-used.
 */
class AuditTestWidgetMisconfigured extends Model
{
    use Auditable;

    protected $table = 'audit_test_widgets';

    protected $guarded = [];

    /** @return list<string>|null */
    protected function auditOnly(): ?array
    {
        return ['name'];
    }

    /** @return list<string>|null */
    protected function auditExcept(): ?array
    {
        return ['internal_notes'];
    }
}
