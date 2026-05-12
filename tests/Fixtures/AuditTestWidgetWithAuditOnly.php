<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Support\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** Fixture — auditOnly() allowlist set; only `name` should be audited. */
class AuditTestWidgetWithAuditOnly extends Model
{
    use Auditable;

    protected $table = 'audit_test_widgets';

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];

    /** @return list<string>|null */
    protected function auditOnly(): ?array
    {
        return ['name'];
    }
}
