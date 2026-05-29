<?php

declare(strict_types=1);

namespace App\Domain\Platform\Models;

use App\Domain\Platform\Enums\ModuleStatus;
use App\Models\User;
use App\Support\Audit\Concerns\Auditable;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\Platform\TenantModuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $module_key
 * @property ModuleStatus $status
 * @property Carbon|null $enabled_at
 * @property int|null $enabled_by_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 *
 * Vendor-side entitlement record. One row per (tenant, module_key)
 * where deleted_at IS NULL (partial unique index). Drives:
 *   - EnforceModuleEntitlement middleware (403 module_not_entitled
 *     when status != Active)
 *   - /auth/me's `entitled_modules` array (Session 5 frontend
 *     filters the launcher accordingly)
 *   - SA-side TenantModuleController::sync (Session 2)
 *
 * Auditable: every status flip writes an audit_log row capturing
 * who flipped, when, and the before/after state. Vital for vendor-
 * side compliance and future billing reconciliation.
 *
 * BelongsToTenant: TenantScope's SA bypass means SA can see all
 * tenant_modules rows in a single query; tenant_users are scoped to
 * their own tenant.
 *
 * enabled_by_user_id is NULLABLE — bootstrap rows from the create
 * migration use NULL ("system bootstrap"). New rows created via SA
 * sync populate it with the SA's id.
 */
class TenantModule extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<TenantModuleFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'tenant_modules';

    protected $fillable = [
        'tenant_id',
        'module_key',
        'status',
        'enabled_at',
        'enabled_by_user_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ModuleStatus::class,
            'enabled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function enabledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enabled_by_user_id');
    }

    /**
     * Factory resolver override — the model lives at
     * App\Domain\Platform\Models\TenantModule but the factory at
     * Database\Factories\Platform\TenantModuleFactory. Without this
     * override, Laravel's auto-resolver would expect
     * Database\Factories\Domain\Platform\Models\TenantModuleFactory.
     * Mirrors the precedent set by Domain/HRM/Models/*.
     */
    protected static function newFactory(): TenantModuleFactory
    {
        return TenantModuleFactory::new();
    }
}
