<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 2A — invitation email.
 *
 * Markdown mailable. Generates responsive HTML + plain-text alternate
 * automatically (deliverability win). Subject + body i18n via
 * `__('invitation.email.*')` against resources/lang/{locale}/invitation.php.
 *
 * Sets the convention for all future emails in this codebase: backend
 * Markdown mailables with backend lang/{locale}/ keys. Frontend i18n
 * (vue-i18n / src/shared/locales/) stays scoped to in-app UI.
 *
 * The mailable carries the rendered greeting/intro/expiry strings
 * pre-computed in the caller (SendInvitationEmailListener) so locale
 * resolution + parameter interpolation happen in one place and the
 * Blade template stays thin.
 *
 * NOT queued via ShouldQueue here — the LISTENER is queued, not the
 * mailable. The listener resolves the locale, constructs the
 * mailable, and dispatches Mail::send synchronously within its own
 * queued job. This keeps the mailable a pure value object and the
 * queue decision a single layer concern.
 */
final class InvitationEmail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $url,
        public string $greeting,
        public string $intro,
        public string $ctaIntro,
        public string $expiry,
        // Property renamed from `subject` to avoid clashing with the
        // parent Mailable's untyped `$subject` (PHPStan rejects the
        // typed override). The envelope reads from $subjectLine.
        public string $subjectLine,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.invitation');
    }
}
