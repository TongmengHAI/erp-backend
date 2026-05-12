<?php

declare(strict_types=1);

namespace App\Providers;

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
        Event::listen(function (JobProcessing $event): void {
            app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        });
    }
}
