<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Exceptions\InvalidInvitationException;
use App\Domain\Identity\Models\Invitation;
use App\Models\User;
use App\Support\Identity\Enums\UserStatus;
use App\Support\Identity\Enums\UserType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Accept an invitation:
 *
 *   1. Hash the raw token from the URL with SHA-256
 *   2. Look up the invitation by hash
 *   3. Verify state — pending (not accepted, cancelled, expired, soft-
 *      deleted by a re-send)
 *   4. Create the User (tenant_id from invitation, status=Active,
 *      password hashed)
 *   5. Assign the Spatie role from invitation (tenant-scoped via
 *      HasTenantRoles)
 *   6. Mark invitation accepted (sets accepted_at + accepted_user_id;
 *      audit row fires through Auditable)
 *
 * Throws InvalidInvitationException (4 mutually-exclusive codes) on
 * any state failure. Self-renders as 422 with stable error_code.
 *
 * Per CLAUDE.md §3 — all multi-row writes inside DB::transaction.
 * The User INSERT + invitation UPDATE + role assignment all commit
 * atomically; an exception anywhere rolls back to a clean state.
 */
final class AcceptInvitationAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function execute(string $rawToken, string $password, ?string $name): InvitationAccepted
    {
        $hash = Invitation::hashToken($rawToken);

        // The lookup runs without TenantScope (Invitation uses
        // BelongsToTenant which would otherwise demand context; this
        // is a public endpoint so no context exists). asSystem clears
        // the scope for the lookup; SoftDeletes still excludes
        // re-sent (soft-deleted) prior tokens — those tokens are
        // structurally invalid by design.
        $invitation = $this->tenantContext->asSystem(
            static fn (): ?Invitation => Invitation::query()->where('token_hash', $hash)->first()
        );

        if ($invitation === null) {
            throw InvalidInvitationException::tokenInvalid();
        }

        $this->assertAcceptable($invitation);

        return DB::transaction(function () use ($invitation, $password, $name): InvitationAccepted {
            return $this->tenantContext->asSystem(function () use ($invitation, $password, $name): InvitationAccepted {
                // Phase 2B walk-fix: this is a PUBLIC endpoint (no auth
                // → no middleware → PermissionRegistrar's team_id is
                // null). Spatie's findByParam then filters
                //   WHERE team_id IS NULL OR team_id = NULL
                // — second clause is FALSE in SQL — so only SYSTEM roles
                // are findable. CUSTOM roles (team_id=$tenant_id) throw
                // RoleDoesNotExist. Latent before Phase 2B; activated by
                // per-tenant custom roles.
                //
                // Same shape + same fix as SendInvitationEmailListener
                // (Phase 2B walk-fix b91ba97). The invitation pins the
                // tenant; set the registrar's team_id from it BEFORE
                // findById so custom + system roles both resolve.
                app(PermissionRegistrar::class)
                    ->setPermissionsTeamId($invitation->tenant_id);

                $user = User::query()->create([
                    'name' => $name ?? $invitation->name ?? $invitation->email,
                    'email' => $invitation->email,
                    'password' => Hash::make($password),
                    'email_verified_at' => now(),
                    'tenant_id' => $invitation->tenant_id,
                    'current_tenant_id' => $invitation->tenant_id,
                    'type' => UserType::TenantUser,
                    'status' => UserStatus::Active,
                ]);

                // Role assignment via the tenant-scoped helper — the
                // invitation carries the role_id; resolve to Role and
                // assign via the tenant team scope.
                $role = Role::findById($invitation->role_id, 'web');
                $tenant = $invitation->tenant;
                if ($tenant !== null) {
                    $user->assignTenantRole($tenant, $role->name);
                }

                // Mark accepted — sets BOTH columns in one save so the
                // composite invitations_accepted_consistency_check CHECK
                // is satisfied (matches §10.4 triple-stack discipline).
                $invitation->accepted_at = now();
                $invitation->accepted_user_id = $user->id;
                $invitation->save();

                return new InvitationAccepted($invitation->fresh() ?? $invitation, $user);
            });
        });
    }

    private function assertAcceptable(Invitation $invitation): void
    {
        if ($invitation->accepted_at !== null) {
            throw InvalidInvitationException::accepted();
        }
        if ($invitation->cancelled_at !== null) {
            throw InvalidInvitationException::cancelled();
        }
        if ($invitation->expires_at->isPast()) {
            throw InvalidInvitationException::expired();
        }
    }
}
