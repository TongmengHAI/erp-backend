<?php

declare(strict_types=1);

namespace App\Web\API\V1\Controllers\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\Enums\TenantStatus;
use App\Support\Tenancy\Scopes\TenantScope;
use App\Web\API\V1\Controllers\Controller;
use App\Web\API\V1\Requests\Auth\LoginRequest;
use App\Web\API\V1\Resources\TenantResource;
use App\Web\API\V1\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class LoginController extends Controller
{
    public function __invoke(LoginRequest $request): JsonResponse
    {
        $email = $request->email();
        $password = $request->password();

        // ============================================================================
        // TIMING-ATTACK MITIGATION — DO NOT "OPTIMIZE" THIS BLOCK.
        //
        // All three operations below run unconditionally on every login attempt, in
        // the same order, regardless of whether they will eventually succeed. This
        // gives every failure branch the same wall-clock cost (≈ bcrypt of one
        // password at BCRYPT_ROUNDS + two indexed SELECTs) and denies an attacker
        // any timing oracle for user-account enumeration.
        //
        // It is tempting to short-circuit when $user === null (skip Hash::check) or
        // when $passwordOk is false (skip the tenant lookup). Doing so re-introduces
        // the timing channel that this code exists to eliminate:
        //
        //   user missing  → fast (~1ms)
        //   wrong password → slow (~bcrypt)
        //   bad tenant    → slow + 1 extra SELECT
        //
        // …a remote attacker can distinguish "user X@example.com exists" from "does
        // not exist" purely from response time. Rate limits help but don't fix the
        // structural leak. The constant-time path does.
        //
        // The dummy hash (cost 12 by default, see config/auth.php) lives outside
        // the codebase as published config and uses an unknown plaintext.
        // ============================================================================

        $user = User::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('email', $email)
            ->first();

        // Pick the inputs for the next two operations. Both branches feed into
        // the SAME Hash::check + SAME Tenant::find call below — only the values
        // differ. This preserves the constant-time guarantee.
        if ($user === null) {
            $passwordHash = (string) config('auth.timing_dummy_hash');
            $tenantId = 0;
        } else {
            $passwordHash = $user->password;
            $tenantId = $user->tenant_id ?? 0;
        }

        $passwordOk = Hash::check($password, $passwordHash);

        $tenant = Tenant::query()->find($tenantId);
        $tenantOk = $tenant !== null && $tenant->status === TenantStatus::Active;

        if ($user === null || ! $passwordOk || ! $tenantOk) {
            // Single generic error. Same body, same status, same response time as
            // every other failure branch — no info leak about which check failed.
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ])->status(401);
        }

        // Reset current_tenant_id to home tenant on every login — no "remember
        // last tenant" state survives logout. Slice 6 will populate this column
        // when a multi-tenant user explicitly switches.
        if ($user->current_tenant_id !== $user->tenant_id) {
            $user->forceFill(['current_tenant_id' => $user->tenant_id])->save();
        }

        Auth::guard('web')->login($user, remember: false);
        $request->session()->regenerate();

        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'tenant' => new TenantResource($tenant),
            ],
        ]);
    }
}
