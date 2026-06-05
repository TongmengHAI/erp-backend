<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Audit\Concerns\Auditable;
use App\Support\Identity\Enums\UserStatus;
use App\Support\Identity\Enums\UserType;
use App\Support\Tenancy\Concerns\HasTenantRoles;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property int|null $tenant_id
 * @property int|null $current_tenant_id
 * @property int|null $default_company_id
 * @property int|null $current_company_id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property UserType $type
 * @property UserStatus $status
 * @property Carbon|null $deleted_at
 * @property string|null $remember_token
 *
 * @todo Add `locale` column (default 'en', accepts 'km') when Khmer translation
 *       work begins. See docs/runbooks/i18n-pending.md for the migration sketch
 *       and frontend Intl integration plan.
 *
 * Note: User does NOT use BelongsToTenant despite carrying `tenant_id`. Applying
 * the global TenantScope here creates a circular dependency: auth resolution
 * must load the user from the DB before ResolveTenant middleware can pin the
 * tenant context, but the scope would demand the context to load the user.
 * User is an identity-source model — it DEFINES tenant membership, it does not
 * live within it. See CLAUDE.md §3 for the full rule. Queries that need
 * "users in tenant X" must use `User::where('tenant_id', $tenantId)` explicitly.
 */
class User extends Authenticatable
{
    use Auditable;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use HasTenantRoles;
    use Notifiable;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'password',
        'tenant_id',
        'current_tenant_id',
        'default_company_id',
        'current_company_id',
        'type',
        'status',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'type' => UserType::class,
            'status' => UserStatus::class,
        ];
    }

    /**
     * Convenience predicate used by LoginController and any future
     * authorization gate. Mirrors isSuperAdmin()'s shape — single
     * source of truth for "is this user currently allowed to act."
     * Soft-delete is checked separately ($notDeleted in the
     * LoginController predicate) so the two dimensions stay
     * independently observable per §10.17.
     */
    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /**
     * Whether this user is a vendor-side platform operator (no tenant, no
     * company, implicit access to /super-admin endpoints). The single
     * source of truth for SA gating across:
     *   - TenantScope global scope (skips the WHERE tenant_id = X)
     *   - ResolveTenant middleware (skips tenant resolution + status check)
     *   - ResolveCompany middleware (skips the 5-branch company resolution)
     *   - UserResource (surfaces type + is_super_admin to the SPA)
     *   - MeController (returns null tenant / company for SA)
     *   - Future SuperAdminGuard middleware (404 for non-SA on /super-admin)
     *
     * Composite DB CHECK guarantees: if isSuperAdmin() is true, the
     * tenant_id / current_tenant_id / default_company_id / current_company_id
     * columns are all NULL.
     */
    public function isSuperAdmin(): bool
    {
        return $this->type === UserType::SuperAdmin;
    }

    /**
     * Home tenant. Previously provided by the BelongsToTenant trait; declared
     * explicitly here now that User is not tenant-scoped.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function currentTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'current_tenant_id');
    }

    /**
     * Preferred home company within the user's tenant. Set at user creation
     * (when at least one company exists) or by BackfillUsersToCompanyAction.
     *
     * @return BelongsTo<Company, $this>
     */
    public function defaultCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'default_company_id');
    }

    /**
     * Currently-active company within the user's tenant. Set by
     * ResolveCompany middleware on each request (header → current →
     * default → sole-fallback). Persists across sessions.
     *
     * @return BelongsTo<Company, $this>
     */
    public function currentCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'current_company_id');
    }
}
