<?php

declare(strict_types=1);

namespace PrivacyCI\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PrivacyCI\Lifecycle\ArrayDeletionStore;
use PrivacyCI\Lifecycle\DeletionRequest;
use PrivacyCI\Lifecycle\DeletionSchedule;
use PrivacyCI\Lifecycle\Events\DeletionCancelled;
use PrivacyCI\Lifecycle\Events\DeletionCompleted;
use PrivacyCI\Lifecycle\Events\DeletionFailed;
use PrivacyCI\Lifecycle\Events\DeletionRequested;
use PrivacyCI\Lifecycle\FrozenClock;
use PrivacyCI\Lifecycle\SubjectDeleter;

final class LifecycleEventsTest extends TestCase
{
    private ArrayDeletionStore $store;

    private FrozenClock $clock;

    /** @var list<object> */
    private array $fired = [];

    protected function setUp(): void
    {
        $this->store = new ArrayDeletionStore;
        $this->clock = FrozenClock::at('2026-01-01T00:00:00Z');
        $this->fired = [];
    }

    private function schedule(?callable $fingerprint = null): DeletionSchedule
    {
        return new DeletionSchedule(
            $this->store,
            $this->clock,
            14,
            emit: function (object $e): void { $this->fired[] = $e; },
            fingerprint: $fingerprint,
        );
    }

    private function deleter(?callable $on = null): SubjectDeleter
    {
        return new class($on) implements SubjectDeleter
        {
            public function __construct(private $on)
            {
            }

            public function delete(DeletionRequest $request): void
            {
                if ($this->on !== null) {
                    ($this->on)($request);
                }
            }
        };
    }

    /** @return list<string> */
    private function names(): array
    {
        return array_map(static fn (object $e): string => (new \ReflectionClass($e))->getShortName(), $this->fired);
    }

    #[Test]
    public function requesting_and_cancelling_are_announced(): void
    {
        $schedule = $this->schedule();
        $schedule->request('user', '1', via: 'account settings');
        $schedule->cancel('user', '1', 'changed their mind');

        $this->assertSame(['DeletionRequested', 'DeletionCancelled'], $this->names());
        $this->assertSame('1', $this->fired[0]->request->subjectId);
    }

    #[Test]
    public function completing_an_erasure_is_announced(): void
    {
        $schedule = $this->schedule();
        $schedule->request('user', '1');
        $this->clock->advance('14 days');
        $schedule->process($this->deleter());

        $this->assertSame(['DeletionRequested', 'DeletionCompleted'], $this->names());
    }

    #[Test]
    public function a_failure_is_announced_too(): void
    {
        $schedule = $this->schedule();
        $schedule->request('user', '1');
        $this->clock->advance('14 days');
        $schedule->process($this->deleter(static function (): void {
            throw new \RuntimeException('storage unreachable');
        }));

        $this->assertSame(['DeletionRequested', 'DeletionFailed'], $this->names());
        $this->assertStringContainsString('storage unreachable', (string) $this->fired[1]->request->resolvedReason);
    }

    #[Test]
    public function a_listener_that_throws_does_not_undo_the_erasure(): void
    {
        $schedule = new DeletionSchedule(
            $this->store,
            $this->clock,
            14,
            emit: static function (object $e): void { throw new \RuntimeException('listener exploded'); },
        );

        $schedule->request('user', '1');
        $this->clock->advance('14 days');
        $result = $schedule->process($this->deleter());

        // Notifying the application is not part of the guarantee.
        $this->assertSame(1, $result['completed']);
    }

    #[Test]
    public function the_policy_in_force_is_recorded_against_the_request(): void
    {
        $schedule = $this->schedule(fn (): string => 'sha256:abc123');
        $request = $schedule->request('user', '1');

        // "Under which rules was this person deleted" is the first question
        // asked once a policy has changed.
        $this->assertSame('sha256:abc123', $request->policyFingerprint);
    }

    #[Test]
    public function a_fingerprint_resolver_that_throws_is_not_fatal(): void
    {
        $schedule = $this->schedule(static fn (): string => throw new \RuntimeException('no policies'));

        $this->assertNull($schedule->request('user', '1')->policyFingerprint);
    }
}
