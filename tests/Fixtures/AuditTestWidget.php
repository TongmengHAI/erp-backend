<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Support\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Test fixture — basic Auditable, no $auditOnly / $auditExcept.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $name
 * @property string|null $email
 * @property string|null $password
 * @property string|null $remember_token
 * @property string|null $internal_notes
 */
class AuditTestWidget extends Model
{
    use Auditable;

    protected $table = 'audit_test_widgets';

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];
}
