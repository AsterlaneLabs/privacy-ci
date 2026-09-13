<?php

declare(strict_types=1);

namespace PrivacyCI\Tests\Feature;

use PrivacyCI\Lifecycle\DeletionRequest;
use PrivacyCI\Lifecycle\SubjectSuspender;

final class RecordingSuspender implements SubjectSuspender
{
    /** @var list<string> */
    public static array $suspended = [];

    /** @var list<string> */
    public static array $reactivated = [];

    public function suspend(DeletionRequest $request): void
    {
        self::$suspended[] = $request->subjectId;
    }

    public function reactivate(DeletionRequest $request): void
    {
        self::$reactivated[] = $request->subjectId;
    }
}
