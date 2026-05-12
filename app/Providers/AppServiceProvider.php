<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Audit\AuditContext;
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

        // AuditContext: lazy per-request snapshot of actor + IP + user-agent.
        // Always built via fromCurrentRequest — that factory handles all the
        // cases (no auth → null actor, no real request → null IP/UA, fake
        // request in tests → captured correctly). Console commands that need
        // actor_type='system' can rebind explicitly via app()->instance(...).
        // Don't gate on runningInConsole — Pest counts as console even when
        // actingAs($user) has populated Auth.
        $this->app->scoped(AuditContext::class, fn () => AuditContext::fromCurrentRequest());
    }

    public function boot(): void
    {
        $this->configureRateLimiters();
        $this->resetSpatieTeamIdBetweenQueueJobs();
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
