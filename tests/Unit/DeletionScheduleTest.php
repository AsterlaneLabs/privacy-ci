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
use PrivacyCI\Lifecycle\NullDeleter;
use PrivacyCI\Lifecycle\SubjectDeleter;

final class DeletionScheduleTest extends TestCase
{
    private ArrayDeletionStore $store;

    private FrozenClock $clock;

    private DeletionSchedule $schedule;

    protected function setUp(): void
    {
        $this->store = new ArrayDeletionStore;
        $this->clock = FrozenClock::at('2026-01-01T00:00:00Z');
        $this->schedule = new DeletionSchedule($this->store, $this->clock);
    }

    private function deleter(?callable $onDelete = null): SubjectDeleter
    {
        return new class($onDelete) implements SubjectDeleter
        {
            public array $deleted = [];

            public function __construct(private $onDelete)
            {
            }

            public function delete(DeletionRequest $request): void
            {
                if ($this->onDelete !== null) {
                    ($this->onDelete)($request);
                }

                $this->deleted[] = $request->subjectId;
            }
        };
    }

    #[Test]
    public function a_request_starts_the_default_fourteen_day_clock(): void
    {
        $request = $this->schedule->request('user', '123', via: 'account settings');

        $this->assertSame(DeletionStatus::PendingDelete, $request->status);
        $this->assertSame('2026-01-15T00:00:00+00:00', $request->executeAfter->format(DATE_ATOM));
        $this->assertSame(14, $request->daysRemaining($this->clock->now()));
        $this->assertSame('account settings', $request->requestedVia);
    }

    #[Test]
    public function the_grace_period_is_configurable(): void
    {
        $schedule = new DeletionSchedule($this->store, $this->clock, graceDays: 7);

        $this->assertSame(
            '2026-01-08T00:00:00+00:00',
            $schedule->request('user', '123')->executeAfter->format(DATE_ATOM),
        );
    }

    #[Test]
    public function asking_twice_does_not_buy_another_window(): void
    {
        $first = $this->schedule->request('user', '123');

        $this->clock->advance('10 days');
        $second = $this->schedule->request('user', '123');

        $this->assertSame($first->id, $second->id);
        $this->assertEquals($first->executeAfter, $second->executeAfter);
        $this->assertCount(1, $this->store->all());
    }

    #[Test]
    public function signing_in_during_the_window_calls_it_off(): void
    {
        $this->schedule->request('user', '123');
        $this->clock->advance('5 days');

        $cancelled = $this->schedule->cancelOnActivity('user', '123');

        $this->assertSame(DeletionStatus::Cancelled, $cancelled?->status);
        $this->assertStringContainsString('signed in', (string) $cancelled?->resolvedReason);
        $this->assertNull($this->schedule->pendingFor('user', '123'));
    }

    #[Test]
    public function cancelling_when_nothing_is_pending_is_harmless(): void
    {
        $this->assertNull($this->schedule->cancelOnActivity('user', 'nobody'));
    }

    #[Test]
    public function a_cancelled_request_never_becomes_due(): void
    {
        $this->schedule->request('user', '123');
        $this->schedule->cancelOnActivity('user', '123');

        $this->clock->advance('60 days');

        $this->assertSame([], $this->schedule->due());
    }

    #[Test]
    public function nothing_is_due_before_the_window_closes(): void
    {
        $this->schedule->request('user', '123');

        $this->clock->advance('13 days');
        $this->assertSame([], $this->schedule->due(), 'day 13 is still inside the window');

        $this->clock->advance('1 day');
        $this->assertCount(1, $this->schedule->due(), 'day 14 is due');
    }

    #[Test]
    public function processing_deletes_and_completes(): void
    {
        $this->schedule->request('user', '123');
        $this->clock->advance('30 days');

        $deleter = $this->deleter();
        $result = $this->schedule->process($deleter);

        $this->assertSame(['completed' => 1, 'failed' => 0, 'errors' => []], $result);
        $this->assertSame(['123'], $deleter->deleted);
        $this->assertSame(DeletionStatus::Completed, $this->store->all()[0]->status);
    }

    #[Test]
    public function one_failure_does_not_block_everyone_else(): void
    {
        $this->schedule->request('user', 'good-1');
        $this->schedule->request('user', 'broken');
        $this->schedule->request('user', 'good-2');
        $this->clock->advance('30 days');

        $deleter = $this->deleter(function (DeletionRequest $r): void {
            if ($r->subjectId === 'broken') {
                throw new \RuntimeException('S3 bucket unreachable');
            }
        });

        $result = $this->schedule->process($deleter);

        $this->assertSame(2, $result['completed']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame('S3 bucket unreachable', $result['errors']['broken']);
    }

    #[Test]
    public function a_failed_request_stays_visible_rather_than_vanishing(): void
    {
        $this->schedule->request('user', '123');
        $this->clock->advance('30 days');

        $this->schedule->process($this->deleter(static function (): void {
            throw new \RuntimeException('connector timed out');
        }));

        $request = $this->store->all()[0];

        $this->assertSame(DeletionStatus::Failed, $request->status);
        $this->assertSame('connector timed out', $request->resolvedReason);
        $this->assertSame(1, $request->attempts);
        $this->assertFalse($request->isDue($this->clock->now()), 'not retried blindly');
    }

    #[Test]
    public function a_claimed_request_is_not_picked_up_twice(): void
    {
        $this->schedule->request('user', '123');
        $this->clock->advance('30 days');

        $seen = [];
        $this->schedule->process($this->deleter(function (DeletionRequest $r) use (&$seen): void {
            // Mid-flight, a second scheduler tick must find nothing to do.
            $seen[] = count($this->schedule->due());
        }));

        $this->assertSame([0], $seen);
    }

    #[Test]
    public function the_default_deleter_refuses_to_pretend(): void
    {
        $this->schedule->request('user', '123');
        $this->clock->advance('30 days');

        $result = $this->schedule->process(new NullDeleter);

        $this->assertSame(1, $result['failed']);
        $this->assertStringContainsString('No SubjectDeleter is bound', $result['errors']['123']);
        $this->assertSame(DeletionStatus::Failed, $this->store->all()[0]->status);
    }

    #[Test]
    public function a_negative_grace_period_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DeletionSchedule($this->store, $this->clock, graceDays: -1);
    }

    #[Test]
    public function zero_days_means_immediate_which_is_allowed_but_explicit(): void
    {
        $schedule = new DeletionSchedule($this->store, $this->clock, graceDays: 0);
        $schedule->request('user', '123');

        $this->assertCount(1, $schedule->due(), 'a zero grace period must be a deliberate choice');
    }
}
