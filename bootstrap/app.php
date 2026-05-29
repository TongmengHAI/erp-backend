<?php

declare(strict_types=1);

use App\Domain\Platform\Exceptions\ModuleNotEntitledException;
use App\Models\Company;
use App\Support\Audit\Console\CreateAuditPartitionsCommand;
use App\Support\Company\Enums\CompanyStatus;
use App\Support\Company\Exceptions\CompanyContextMissingException;
use App\Support\Company\Middleware\ResolveCompany;
use App\Support\Tenancy\Exceptions\TenantInactiveException;
use App\Support\Tenancy\Middleware\ResolveTenant;
use App\Web\API\V1\Middleware\EnforceModuleEntitlement;
use App\Web\API\V1\Middleware\SuperAdminGuard;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    // Console commands outside app/Console/Commands/ need explicit registration.
    // Our domain layout (§5.1) puts framework commands under app/Support/<X>/Console/.
    ->withCommands([
        CreateAuditPartitionsCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->alias([
            'tenant' => ResolveTenant::class,
            // ResolveCompany must run AFTER ResolveTenant — it depends on
            // TenantContext being populated. Routes that need company
            // context apply the middleware stack ['auth:sanctum', 'tenant',
            // 'company']; opt out per-route via meta companyOptional=true.
            'company' => ResolveCompany::class,
            // Module entitlement gate. Parameterized: 'module:hrm'. Runs
            // AFTER 'tenant' — depends on TenantContext for the tenant
            // being checked. Applied to module-prefixed route groups
            // (hrm/* AND admin/hrm/* — tenant_admin can't see HRM
            // Settings when HRM is disabled; only SA can re-enable).
            'module' => EnforceModuleEntitlement::class,
            // SA-only gate. Returns 404 (not 403) for non-SA users per
            // Q8. Applied to the super-admin/* route group.
            'super_admin' => SuperAdminGuard::class,
        ]);

        // Force SubstituteBindings (route-model binding) to run AFTER our
        // auth/tenant/company context middleware. Without this, implicit
        // model binding on tenant+company-scoped routes (e.g.
        // /api/v1/hrm/employees/{employee}) tries to query Employee before
        // TenantContext/CompanyContext are set, tripping the global scopes.
        // Default Laravel priority puts SubstituteBindings late in the chain
        // already, but Laravel 12's statefulApi() reorders things — being
        // explicit here is the safe fix.
        $middleware->priority([
            HandlePrecognitiveRequests::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            VerifyCsrfToken::class,
            AuthenticateSession::class,
            Authenticate::class,
            ResolveTenant::class,
            ResolveCompany::class,
            SubstituteBindings::class,
            Authorize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Render TenantInactiveException with a stable error_code so the SPA
        // can route to a "tenant suspended" screen instead of /login.
        $exceptions->render(function (TenantInactiveException $e, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
            ], $e->getStatusCode());
        });

        // Render CompanyContextMissingException with error_code='company_required'
        // plus an available_companies array so the SPA can render a picker.
        // We compute available_companies lazily here (rather than in the
        // exception itself) because the exception is also thrown from
        // non-request contexts (CompanyScope, BelongsToCompany) where there
        // is no current user.
        // Render ModuleNotEntitledException with error_code='module_not_entitled'
        // plus the module key. The SPA shows a module-specific "disabled"
        // screen ("HRM is disabled for your organisation").
        $exceptions->render(function (ModuleNotEntitledException $e, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
                'module' => $e->moduleKey,
            ], $e->getStatusCode());
        });

        $exceptions->render(function (CompanyContextMissingException $e, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            $available = [];
            $user = $request->user();
            if ($user !== null && $user->tenant_id !== null) {
                $available = Company::query()
                    ->where('tenant_id', $user->tenant_id)
                    ->where('status', CompanyStatus::Active->value)
                    ->get(['id', 'slug', 'name', 'status'])
                    ->map(fn (Company $c): array => [
                        'id' => $c->id,
                        'slug' => $c->slug,
                        'name' => $c->name,
                        'status' => $c->status->value,
                    ])
                    ->all();
            }

            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
                'available_companies' => $available,
            ], $e->getStatusCode());
        });
    })->create();
