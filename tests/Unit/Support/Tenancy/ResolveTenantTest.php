<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Support\Identity\Enums\UserType;
use App\Support\Tenancy\Exceptions\TenantAccessDeniedException;
use App\Support\Tenancy\Exceptions\TenantInactiveException;
use App\Support\Tenancy\Middleware\ResolveTenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

function runMiddleware(?User $user): Response
{
    $request = Request::create('/test');
    $request->setUserResolver(fn () => $user);

    return app(ResolveTenant::class)->handle($request, fn () => new Response('OK'));
}

it('resolves to user.current_tenant_id when it is set', function (): void {
    $home = Tenant::factory()->create();
    $active = Tenant::factory()->create();
    $user = User::factory()->forTenant($home)->create(['current_tenant_id' => $active->id]);

    runMiddleware($user);

    expect(app(TenantContext::class)->current()->id)->toBe($active->id);
});

it('falls back to user.tenant_id when current_tenant_id is null', function (): void {
    $home = Tenant::factory()->create();
    $user = User::factory()->forTenant($home)->create(['current_tenant_id' => null]);

    runMiddleware($user);

    expect(app(TenantContext::class)->current()->id)->toBe($home->id);
});

it('passes through unauthenticated requests without setting the context', function (): void {
    $response = runMiddleware(null);

    expect($response->getContent())->toBe('OK');
    expect(app(TenantContext::class)->current())->toBeNull();
});

it('throws 403 TenantAccessDeniedException when user has neither tenant_id nor current_tenant_id', function (): void {
    // The composite DB CHECK 'users_tenant_user_has_tenant_check' added
    // in Session 1 makes this orphan state unreachable via normal Eloquent
    // flows — a persisted tenant_user with NULL tenant_id rejects at the
    // DB layer. The defensive throw in ResolveTenant::resolveFor still
    // exists as a runtime guard (against e.g. partial-migration state),
    // and we exercise it here by constructing the User in-memory without
    // persisting. runMiddleware doesn't touch the DB on the user side —
    // it sets the request's user resolver to the in-memory instance.
    $user = new User;
    $user->tenant_id = null;
    $user->current_tenant_id = null;
    $user->type = UserType::TenantUser;

    expect(fn () => runMiddleware($user))
        ->toThrow(TenantAccessDeniedException::class, 'has no resolvable tenant');
});

it('throws 403 when the resolved tenant has been soft-deleted', function (): void {
    // FK uses ON DELETE RESTRICT, so a tenant with users can't be hard-deleted.
    // The realistic "tenant gone" scenario is soft-delete by a tenant admin —
    // the row exists but Tenant::query() filters trashed rows by default.
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create();

    $tenant->delete();

    expect(fn () => runMiddleware($user->fresh()))
        ->toThrow(TenantAccessDeniedException::class, 'does not exist');
});

it('throws 401 with error_code=tenant_inactive when the resolved tenant is suspended', function (): void {
    $tenant = Tenant::factory()->suspended()->create();
    $user = User::factory()->forTenant($tenant)->create();

    try {
        runMiddleware($user);
        throw new RuntimeException('Expected TenantInactiveException, none thrown.');
    } catch (TenantInactiveException $e) {
        expect($e->getStatusCode())->toBe(401);
        expect($e->errorCode)->toBe('tenant_inactive');
        expect($e->getMessage())->toContain('suspended');
    }
});

it('TenantAccessDeniedException renders as HTTP 403', function (): void {
    $exception = new TenantAccessDeniedException('test');

    expect($exception->getStatusCode())->toBe(403);
});

it('TenantInactiveException renders as HTTP 401 with tenant_inactive code', function (): void {
    $exception = new TenantInactiveException('test');

    expect($exception->getStatusCode())->toBe(401);
    expect($exception->errorCode)->toBe('tenant_inactive');
});
