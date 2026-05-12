<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Support\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Fixture for soft_deleted / restored action tests. Uses SoftDeletes so the
 * Auditable trait's restored event hook engages and `deleted` distinguishes
 * soft vs hard delete via isForceDeleting().
 *
 * @property int $id
 * @property string $name
 * @property Carbon|null $deleted_at
 */
class AuditTestSoftWidget extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $table = 'audit_test_soft_widgets';

    protected $guarded = [];
}
