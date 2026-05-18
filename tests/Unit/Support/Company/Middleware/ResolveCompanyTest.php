<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Company\CompanyContext;
use App\Support\Company\Exceptions\CompanyAccessDeniedException;
use App\Support\Company\Exceptions\CompanyContextMissingException;
use App\Support\Company\Middleware\ResolveCompany;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drive ResolveCompany through every branch of its resolution chain.
 *
 * Helper builds a Request with the user resolver populated, runs the
 * tenant context setup manually (since we're isolating ResolveCompany),
 * then invokes the middleware. `$option` simulates the middleware
 * parameter that routes pass via 'company:optional'.
 */
function runCompanyMiddleware(
    ?User $user,
    ?Tenant $tenant,
    ?string $headerCompanyId = null,
    bool $companyOptional = false,
): Response {
    $request = Request::create('/test');
    $request->setUserResolver(fn () => $user);
    if ($headerCompanyId !== null) {
        $request->headers->set('X-Company-Id', $headerCompanyId);
    }

    if ($tenant !== null) {
        app(TenantContext::class)->setCurrent($tenant);
    }

    $option = $companyOptional ? 'optional' : null;

    return app(ResolveCompany::class)->handle(
        $request,
        fn () => new Response('OK'),
        $option,
    );
}

beforeEach(function (): void {
    // Reset contexts between tests so prior state doesn't bleed.
    app(TenantContext::class)->setCurrent(null);
    app(CompanyContext::class)->setCurrent(null);
});

it('passes through unauthenticated requests without setting company context', function (): void {
    $response = runCompanyMiddleware(user: null, tenant: null);

    expect($response->getContent())->toBe('OK');
    expect(app(CompanyContext::class)->current())->toBeNull();
});

it('passes through when authenticated but no tenant is resolved', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create();

    // Tenant explicitly NOT set in context.
    $response = runCompanyMiddleware($user, tenant: null);

    expect($response->getContent())->toBe('OK');
    expect(app(CompanyContext::class)->current())->toBeNull();
});

it('Step 1: resolves from X-Company-Id header and persists current_company_id', function (): void {
    $tenant = Tenant::factory()->create();
    $company = Company::factory()->forTenant($tenant)->create();
    $user = User::factory()->forTenant($tenant)->create(['current_company_id' => null]);

    runCompanyMiddleware($user, $tenant, headerCompanyId: (string) $company->id);

    expect(app(CompanyContext::class)->current()->id)->toBe($company->id);
    expect($user->fresh()->current_company_id)->toBe($company->id);
});

it('Step 1: throws 403 CompanyAccessDeniedException when header points at a non-existent company', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create();
    Company::factory()->forTenant($tenant)->create(); // unrelated

    expect(fn () => runCompanyMiddleware($user, $tenant, headerCompanyId: '999999'))
        ->toThrow(CompanyAccessDeniedException::class);
});

it('Step 1: throws 403 when header points at a company in a different tenant', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $companyB = Company::factory()->forTenant($tenantB)->create();
    $user = User::factory()->forTenant($tenantA)->create();

    expect(fn () => runCompanyMiddleware($user, $tenantA, headerCompanyId: (string) $companyB->id))
        ->toThrow(CompanyAccessDeniedException::class);
});

it('Step 1: throws 403 when header points at an archived company', function (): void {
    $tenant = Tenant::factory()->create();
    $archived = Company::factory()->forTenant($tenant)->archived()->create();
    $user = User::factory()->forTenant($tenant)->create();

    expect(fn () => runCompanyMiddleware($user, $tenant, headerCompanyId: (string) $archived->id))
        ->toThrow(CompanyAccessDeniedException::class);
});

it('Step 2: resolves from user.current_company_id when set and valid', function (): void {
    $tenant = Tenant::factory()->create();
    $company = Company::factory()->forTenant($tenant)->create();
    $user = User::factory()->forTenant($tenant)->create([
        'current_company_id' => $company->id,
    ]);

    runCompanyMiddleware($user, $tenant);

    expect(app(CompanyContext::class)->current()->id)->toBe($company->id);
});

it('Step 2: clears stale current_company_id when it points at an inaccessible company, then falls through', function (): void {
    $tenant = Tenant::factory()->create();
    $valid = Company::factory()->forTenant($tenant)->create();
    $archived = Company::factory()->forTenant($tenant)->archived()->create();
    $user = User::factory()->forTenant($tenant)->create([
        'current_company_id' => $archived->id,
        'default_company_id' => $valid->id, // step 3 picks this up
    ]);

    runCompanyMiddleware($user, $tenant);

    expect(app(CompanyContext::class)->current()->id)->toBe($valid->id);
    // Stale current was cleared, then Step 3 wrote it to the valid default.
    expect($user->fresh()->current_company_id)->toBe($valid->id);
});

it('Step 3: resolves from user.default_company_id and promotes it to current', function (): void {
    $tenant = Tenant::factory()->create();
    $company = Company::factory()->forTenant($tenant)->create();
    $user = User::factory()->forTenant($tenant)->create([
        'current_company_id' => null,
        'default_company_id' => $company->id,
    ]);

    runCompanyMiddleware($user, $tenant);

    expect(app(CompanyContext::class)->current()->id)->toBe($company->id);
    expect($user->fresh()->current_company_id)->toBe($company->id);
});

it('Step 4: sole-company fallback fires when count===1 and backfills both default + current', function (): void {
    $tenant = Tenant::factory()->create();
    $sole = Company::factory()->forTenant($tenant)->create();
    $user = User::factory()->forTenant($tenant)->create([
        'current_company_id' => null,
        'default_company_id' => null,
    ]);

    runCompanyMiddleware($user, $tenant);

    expect(app(CompanyContext::class)->current()->id)->toBe($sole->id);
    $fresh = $user->fresh();
    expect($fresh->current_company_id)->toBe($sole->id);
    expect($fresh->default_company_id)->toBe($sole->id);
});

it('Step 4: does NOT fire when tenant has 2+ active companies', function (): void {
    $tenant = Tenant::factory()->create();
    Company::factory()->forTenant($tenant)->create();
    Company::factory()->forTenant($tenant)->create();
    $user = User::factory()->forTenant($tenant)->create([
        'current_company_id' => null,
        'default_company_id' => null,
    ]);

    // Step 5 path: no opt-out, must throw.
    expect(fn () => runCompanyMiddleware($user, $tenant))
        ->toThrow(CompanyContextMissingException::class);
});

it('Step 4: does NOT fire when tenant has 0 active companies', function (): void {
    $tenant = Tenant::factory()->create();
    Company::factory()->forTenant($tenant)->archived()->create(); // archived
    $user = User::factory()->forTenant($tenant)->create([
        'current_company_id' => null,
        'default_company_id' => null,
    ]);

    expect(fn () => runCompanyMiddleware($user, $tenant))
        ->toThrow(CompanyContextMissingException::class);
});

it('Step 5: throws CompanyContextMissingException when no branch matches and route is not optional', function (): void {
    $tenant = Tenant::factory()->create();
    Company::factory()->forTenant($tenant)->create();
    Company::factory()->forTenant($tenant)->create();
    $user = User::factory()->forTenant($tenant)->create([
        'current_company_id' => null,
        'default_company_id' => null,
    ]);

    expect(fn () => runCompanyMiddleware($user, $tenant, companyOptional: false))
        ->toThrow(CompanyContextMissingException::class);
});

it('Step 5: returns 200 with null context when no branch matches but route is companyOptional', function (): void {
    $tenant = Tenant::factory()->create();
    Company::factory()->forTenant($tenant)->create();
    Company::factory()->forTenant($tenant)->create();
    $user = User::factory()->forTenant($tenant)->create([
        'current_company_id' => null,
        'default_company_id' => null,
    ]);

    $response = runCompanyMiddleware($user, $tenant, companyOptional: true);

    expect($response->getContent())->toBe('OK');
    expect(app(CompanyContext::class)->current())->toBeNull();
});
