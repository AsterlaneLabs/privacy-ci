<?php

declare(strict_types=1);

namespace PrivacyCI\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PrivacyCI\Lifecycle\ArrayDeletionStore;
use PrivacyCI\Lifecycle\DeletionRequest;
use PrivacyCI\Lifecycle\DeletionSchedule;
use PrivacyCI\Lifecycle\DeletionStatus;
use PrivacyCI\Lifecycle\FrozenClock;
use PrivacyCI\Lifecycle\LifecycleMode;
use PrivacyCI\Lifecycle\NullSuspender;
use PrivacyCI\Lifecycle\SubjectSuspender;

final class SuspendAndHoldTest extends TestCase
{
    private ArrayDeletionStore $store;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->store = new ArrayDeletionStore;
        $this->clock = FrozenClock::at('2026-01-01T00:00:00Z');
    }

    private function suspender(?callable $onSuspend = null): SubjectSuspender
    {
        return new class($onSuspend) implements SubjectSuspender
        {
            /** @var list<string> */
            public array $suspended = [];

            /** @var list<string> */
            public array $reactivated = [];

            public function __construct(private $onSuspend)
            {
            }

            public function suspend(DeletionRequest $request): void
            {
                if ($this->onSuspend !== null) {
                    ($this->onSuspend)($request);
                }

                $this->suspended[] = $request->subjectId;
            }

            public function reactivate(DeletionRequest $request): void
            {
                $this->reactivated[] = $request->subjectId;
            }
        };
    }

    private function schedule(SubjectSuspender $suspender, int $days = 14): DeletionSchedule
    {
        return new DeletionSchedule(
            $this->store,
            $this->clock,
            $days,
            LifecycleMode::Suspend,
            $suspender,
        );
    }

    #[Test]
    public function requesting_stops_processing_on_day_zero(): void
    {
        $suspender = $this->suspender();
        $request = $this->schedule($suspender)->request('user', '123', via: 'account settings');

        $this->assertSame(['123'], $suspender->suspended);
        $this->assertTrue($request->isSuspended());
        $this->assertSame('2026-01-01T00:00:00+00:00', $request->suspendedAt?->format(DATE_ATOM));
    }

    #[Test]
    public function suspension_does_not_delete_anything_yet(): void
    {
        $request = $this->schedule($this->suspender())->request('user', '123');

        $this->assertSame(DeletionStatus::PendingDelete, $request->status);
        $this->assertSame('2026-01-15T00:00:00+00:00', $request->executeAfter->format(DATE_ATOM));
        $this->assertFalse($request->isDue($this->clock->now()));
    }

    #[Test]
    public function reactivating_restores_the_account_and_calls_off_the_erasure(): void
    {
        $suspender = $this->suspender();
        $schedule = $this->schedule($suspender);
        $schedule->request('user', '123');

        $this->clock->advance('5 days');
        $cancelled = $schedule->reactivate('user', '123');

        $this->assertSame(['123'], $suspender->reactivated);
        $this->assertSame(DeletionStatus::Cancelled, $cancelled?->status);
        $this->assertNull($schedule->pendingFor('user', '123'));
    }

    #[Test]
    public function login_no_longer_cancels_under_suspension(): void
    {
        $schedule = $this->schedule($this->suspender());
        $schedule->request('user', '123');

        // The subject cannot sign in while suspended, so an implicit login must
        // not be treated as consent to keep the account.
        $this->assertNull($schedule->cancelOnActivity('user', '123'));
        $this->assertNotNull($schedule->pendingFor('user', '123'));
    }

    #[Test]
    public function hold_mode_still_cancels_on_login(): void
    {
        $schedule = new DeletionSchedule($this->store, $this->clock, 14, LifecycleMode::Hold);
        $schedule->request('user', '123');

        $this->assertNotNull($schedule->cancelOnActivity('user', '123'));
    }

    #[Test]
    public function a_failed_suspension_rolls_the_request_back(): void
    {
        $suspender = $this->suspender(static function (): void {
            throw new \RuntimeException('identity provider unreachable');
        });

        try {
            $this->schedule($suspender)->request('user', '123');
            $this->fail('the failure should surface to the caller');
        } catch (\RuntimeException $e) {
            $this->assertSame('identity provider unreachable', $e->getMessage());
        }

        // Believing you are closed while still fully active is the one outcome
        // worse than the request not being accepted at all.
        $request = $this->store->all()[0];

        $this->assertSame(DeletionStatus::Cancelled, $request->status);
        $this->assertStringContainsString('suspension failed', (string) $request->resolvedReason);
        $this->assertNull($this->store->pendingFor('user', '123'));
    }

    #[Test]
    public function suspend_mode_without_a_suspender_is_refused_at_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/requires a SubjectSuspender/');

        new DeletionSchedule($this->store, $this->clock, 14, LifecycleMode::Suspend);
    }

    #[Test]
    public function the_null_suspender_refuses_to_pretend(): void
    {
        $schedule = $this->schedule(new NullSuspender);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/no SubjectSuspender is bound/');

        $schedule->request('user', '123');
    }

    #[Test]
    public function reactivating_nothing_is_harmless(): void
    {
        $this->assertNull($this->schedule($this->suspender())->reactivate('user', 'nobody'));
    }

    #[Test]
    public function a_suspended_subject_is_still_erased_when_the_window_closes(): void
    {
        $suspender = $this->suspender();
        $schedule = $this->schedule($suspender);
        $schedule->request('user', '123');

        $this->clock->advance('14 days');

        $this->assertCount(1, $schedule->due());
        $this->assertSame([], $suspender->reactivated, 'they did not come back');
    }

    #[Test]
    public function hold_mode_never_touches_the_suspender(): void
    {
        $suspender = $this->suspender();
        $schedule = new DeletionSchedule($this->store, $this->clock, 14, LifecycleMode::Hold, $suspender);

        $schedule->request('user', '123');
        $schedule->reactivate('user', '123');

        $this->assertSame([], $suspender->suspended);
        $this->assertSame([], $suspender->reactivated);
    }
}
