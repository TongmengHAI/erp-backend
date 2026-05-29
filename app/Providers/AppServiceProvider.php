<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\HRM\Listeners\BootstrapHrmSettingsListener;
use App\Domain\HRM\Services\HrmSettingsRepository;
use App\Support\Audit\AuditContext;
use App\Support\Company\CompanyContext;
use App\Support\Company\Events\CompanyCreated;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(CompanyContext::class);

        // AuditContext: lazy per-request snapshot of actor + IP + user-agent.
        // Always built via fromCurrentRequest — that factory handles all the
        // cases (no auth → null actor, no real request → null IP/UA, fake
        // request in tests → captured correctly). Console commands that need
        // actor_type='system' can rebind explicitly via app()->instance(...).
        // Don't gate on runningInConsole — Pest counts as console even when
        // actingAs($user) has populated Auth.
        $this->app->scoped(AuditContext::class, fn () => AuditContext::fromCurrentRequest());

        // HrmSettingsRepository: per-request cache. `scoped` is request-
        // lifetime in Laravel's container, so the singleton's in-memory
        // cache lasts for one HTTP request / one Artisan command / one
        // queue job and resets cleanly between. See the repository's
        // own docblock for the cache invariant.
        $this->app->scoped(HrmSettingsRepository::class);
    }

    public function boot(): void
    {
        $this->configureRateLimiters();
        $this->resetSpatieTeamIdBetweenQueueJobs();
        $this->registerDomainEventListeners();
    }

    /**
     * Subscribe domain listeners to cross-domain events. Centralised
     * here in v1 because there's only one event/listener pair; if the
     * surface grows past ~5 pairs, extract to a dedicated
     * DomainEventServiceProvider.
     */
    private function registerDomainEventListeners(): void
    {
        // HRM listens for company bootstrap.
        Event::listen(CompanyCreated::class, BootstrapHrmSettingsListener::class);
    }

    private function configureRateLimiters(): void
    {
        // Login limiter: 5 attempts per minute, keyed by IP + lowercased email.
        // Composite key defeats credential-stuffing from one IP across many
        // usernames AND distributed attacks targeting a single user.
        RateLimiter::for('login', function (Request $request): Limit {
            return Limit::perMinute(5)->by(
                $request->ip().':'.Str::lower((string) $request->input('email'))
            );
        });
    }

    /**
     * Spatie's PermissionRegistrar holds team_id in process-global state.
     * A queue worker processing jobs for tenant A and then tenant B would
     * leak A's team_id into B's job if a job forgets to set it. Reset to
     * null before every job so each job's own tenant resolution runs from
     * a clean slate.
     */
    private function resetSpatieTeamIdBetweenQueueJobs(): void
    {
        Event::listen(JobProcessing::class, function (): void {
            app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        });
    }
}
