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
 * The last-chance notice, sent from your SubjectNotifier.
 *
 *     Mail::to($user)->send(new DeletionReminderMail($request, $daysRemaining));
 */
class DeletionReminderMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly DeletionRequest $deletionRequest,
        public readonly int $daysRemaining,
        public readonly ?string $appName = null,
    ) {
    }

    public function envelope(): Envelope
    {
        $name = $this->appName ?? config('app.name', 'your account');

        return new Envelope(
            subject: $this->daysRemaining <= 1
                ? sprintf('Last chance: your %s account is deleted tomorrow', $name)
                : sprintf('Your %s account is deleted in %d days', $name, $this->daysRemaining),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'privacy::mail.deletion-reminder',
            with: [
                'days' => $this->daysRemaining,
                'deletesOn' => $this->deletionRequest->executeAfter,
                'reactivateUrl' => app(ReactivationLink::class)->for($this->deletionRequest),
                'urgent' => $this->daysRemaining <= 1,
            ],
        );
    }
}
