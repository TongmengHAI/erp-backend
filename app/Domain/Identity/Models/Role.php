<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Support\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * @property int $id
 * @property int|null $team_id
 * @property string $name
 * @property string $guard_name
 * @property bool $is_system
 * @property string|null $description
 * @property Carbon|null $deleted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * Phase 2B — extended Role model.
 *
 * Wraps Spatie's Role with three project-specific concerns:
 *
 *   1. Auditable — every create / update / soft-delete writes an
 *      audit row. Per §10.24 the trait emits action='soft_deleted'
 *      (NOT 'deleted') for SoftDeletes models; that value is pinned
 *      by RoleAuditableTest at the trait-using layer + later by the
 *      Session 2 DeleteRoleAction's audit test at the Action layer.
 *
 *   2. SoftDeletes — custom roles are soft-deletable. The
 *      model_has_roles join rows are preserved on delete, so
 *      restoring the role restores effective permissions to the
 *      assigned users. System roles are never soft-deleted (the
 *      Session 2 DeleteRoleAction rejects is_system=true with 403).
 *
 *   3. is_system + description casts. is_system is the immutable
 *      "this is a framework-provided role" flag; Session 2's
 *      UpdateRoleAction rejects mutation when is_system=true.
 *
 * Tenant isolation via team_id (Spatie's teams feature). System rows
 * have team_id=NULL (global, reused across tenants); custom rows
 * have team_id=$tenant_id. The scopeForTenant scope returns both
 * (system rows + the tenant's custom rows) — that's the canonical
 * "what roles can this tenant see" query.
 */
class Role extends SpatieRole
{
    use Auditable;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'guard_name',
        'team_id',
        'is_system',
        'description',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    /**
     * System roles (is_system=true). Three rows post-seed:
     * tenant_admin, accountant, viewer. All have team_id=NULL.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('is_system', true);
    }

    /**
     * Custom roles (is_system=false). Always tenant-scoped via
     * team_id. Use scopeForTenant for the read query — this scope
     * alone returns custom rows from EVERY tenant, which is rarely
     * what callers want.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCustom(Builder $query): Builder
    {
        return $query->where('is_system', false);
    }

    /**
     * The canonical "what roles can this tenant see" query. Returns
     * system rows (global, team_id=NULL) PLUS the tenant's custom
     * rows (team_id=$tenantId). Soft-deleted custom rows are excluded
     * by the SoftDeletes default scope.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where(function (Builder $q) use ($tenantId): void {
            $q->where('is_system', true)
                ->orWhere('team_id', $tenantId);
        });
    }
}
