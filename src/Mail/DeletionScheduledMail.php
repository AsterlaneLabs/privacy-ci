<?php

declare(strict_types=1);

namespace PrivacyCI\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use PrivacyCI\Lifecycle\DeletionRequest;
use PrivacyCI\Lifecycle\Laravel\ReactivationLink;

/**
 * Tells a subject their account is closing, and how to stop it.
 *
 * Send it from your SubjectSuspender, where you already have the user and their
 * email address. The package does not try to work out how to contact a
 * subject, because only the application knows that.
 *
 *     Mail::to($user)->send(new DeletionScheduledMail($request));
 *
 * Subclass it, or publish the view, to match your own tone and branding.
 */
class DeletionScheduledMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly DeletionRequest $deletionRequest,
        public readonly ?string $appName = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                'Your %s account is scheduled for deletion',
                $this->appName ?? config('app.name', 'account'),
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'privacy::mail.deletion-scheduled',
            with: [
                'days' => $this->deletionRequest->daysRemaining(now()->toDateTimeImmutable()),
                'deletesOn' => $this->deletionRequest->executeAfter,
                'reactivateUrl' => app(ReactivationLink::class)->for($this->deletionRequest),
                'requestedVia' => $this->deletionRequest->requestedVia,
            ],
        );
    }
}
