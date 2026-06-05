<?php

declare(strict_types=1);

namespace App\Domain\Identity\Listeners;

use App\Domain\Identity\Events\UserInvited;
use App\Mail\InvitationEmail;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;

/**
 * Sends the invitation email when a UserInvited event fires.
 *
 * Queued on the dedicated 'mail' queue per CLAUDE.md §4 precedent
 * (separate from default so a mail backlog doesn't stall other jobs).
 *
 * Mail::send is synchronous within the queued job; the LISTENER's
 * queue tier is what makes the email send async from the HTTP
 * request that triggered the invitation. Keeps the mailable a pure
 * value object — locale + i18n + parameter interpolation all happen
 * here in one place.
 *
 * Failure handling: queue retries are governed by Horizon's
 * supervisor-1 config (tries: 1 by default in this codebase; bump
 * later if needed). Exhausted retries land in failed_jobs and the
 * admin can re-send via the InvitationController.
 */
final class SendInvitationEmailListener implements ShouldQueue
{
    public string $queue = 'mail';

    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function handle(UserInvited $event): void
    {
        // Queued job has no request context, so we set the tenant for
        // any tenant-scoped reads the email rendering needs (the role
        // name lookup goes through Spatie which DOES respect team_id).
        // Wrap in asSystem to clear tenant scoping for the read, then
        // re-apply for the listener's body if anything else needs it.
        $invitation = $event->invitation;

        $this->tenantContext->asSystem(function () use ($event, $invitation): void {
            // Fetch the related rows by FK explicitly. The Invitation's
            // FKs are NOT NULL (enforced by the migration), so the
            // findOrFail calls succeed for any committed invitation —
            // and the typed locals carry that non-nullability through
            // PHPStan's analysis.
            $role = Role::findById($invitation->role_id, 'web');
            $tenant = Tenant::query()->findOrFail($invitation->tenant_id);
            $inviter = User::query()->findOrFail($invitation->invited_by_user_id);
            assert($tenant instanceof Tenant);
            assert($inviter instanceof User);

            $inviteeGreeting = (string) ($invitation->name !== null && $invitation->name !== ''
                ? __('invitation.email.greeting_named', ['name' => $invitation->name])
                : __('invitation.email.greeting_anonymous'));

            $intro = (string) __('invitation.email.intro', [
                'inviter' => $inviter->name,
                'tenant' => $tenant->name,
                'role' => $role->name,
            ]);

            $expiry = (string) __('invitation.email.expiry', [
                'date' => $invitation->expires_at->format('F j, Y \\a\\t g:i A T'),
            ]);

            $subject = (string) __('invitation.email.subject', ['tenant' => $tenant->name]);

            // Build the acceptance URL using the configured frontend
            // base + the public route /invitation/{token}. The raw
            // token comes from the event (NOT from the model — the
            // model only has the hash).
            $url = rtrim(config('app.frontend_url', config('app.url')), '/')
                ."/invitation/{$event->rawToken}";

            Mail::to($invitation->email)->send(new InvitationEmail(
                url: $url,
                greeting: $inviteeGreeting,
                intro: $intro,
                ctaIntro: (string) __('invitation.email.cta_intro'),
                expiry: $expiry,
                subjectLine: $subject,
            ));
        });
    }
}
