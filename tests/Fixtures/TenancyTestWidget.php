<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Throwaway model used by tenancy unit tests. Backing table
 * `tenancy_test_widgets` is created in beforeEach() of each test that
 * needs it and rolled back by RefreshDatabase. Not part of production.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $name
 */
class TenancyTestWidget extends Model
{
    use BelongsToTenant;

    protected $table = 'tenancy_test_widgets';

    protected $guarded = [];
}
