<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Support\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** Fixture — auditExcept() denylist set; `internal_notes` excluded on top of defaults. */
class AuditTestWidgetWithAuditExcept extends Model
{
    use Auditable;

    protected $table = 'audit_test_widgets';

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];

    /** @return list<string>|null */
    protected function auditExcept(): ?array
    {
        return ['internal_notes'];
    }
}
