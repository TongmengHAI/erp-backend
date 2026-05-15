<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Audit\Concerns\Auditable;
use App\Support\Tenancy\Concerns\HasTenantRoles;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property int|null $tenant_id
 * @property int|null $current_tenant_id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
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

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'password',
        'tenant_id',
        'current_tenant_id',
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
        ];
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
}
