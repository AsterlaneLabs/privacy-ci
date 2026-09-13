<?php

declare(strict_types=1);

namespace PrivacyCI\Tests\Feature;

use Illuminate\Support\Facades\Mail;
use PrivacyCI\Lifecycle\DeletionRequest;
use PrivacyCI\Lifecycle\SubjectNotifier;
use PrivacyCI\Mail\DeletionReminderMail;

final class RecordingNotifier implements SubjectNotifier
{
    /** @var list<array{string, int}> */
    public static array $reminded = [];

    public function remind(DeletionRequest $request, int $daysRemaining): void
    {
        self::$reminded[] = [$request->subjectId, $daysRemaining];

        Mail::to('subject@example.com')->send(
            new DeletionReminderMail($request, $daysRemaining),
        );
    }
}
