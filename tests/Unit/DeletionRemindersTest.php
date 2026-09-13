<?php

declare(strict_types=1);

namespace PrivacyCI\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PrivacyCI\Lifecycle\ArrayDeletionStore;
use PrivacyCI\Lifecycle\DeletionRequest;
use PrivacyCI\Lifecycle\DeletionSchedule;
use PrivacyCI\Lifecycle\FrozenClock;
use PrivacyCI\Lifecycle\LifecycleMode;
use PrivacyCI\Lifecycle\NullNotifier;
use PrivacyCI\Lifecycle\SubjectNotifier;

final class DeletionRemindersTest extends TestCase
{
    private ArrayDeletionStore $store;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->store = new ArrayDeletionStore;
        $this->clock = FrozenClock::at('2026-01-01T00:00:00Z');
    }

    /** @param list<int> $marks */
    private function schedule(array $marks = [7, 1], int $days = 14): DeletionSchedule
    {
        return new DeletionSchedule(
            $this->store,
            $this->clock,
            $days,
            LifecycleMode::Hold,
            null,
            $marks,
        );
    }

    private function notifier(?callable $onRemind = null): SubjectNotifier
    {
        return new class($onRemind) implements SubjectNotifier
        {
            /** @var list<array{string, int}> */
            public array $sent = [];

            public function __construct(private $onRemind)
            {
            }

            public function remind(DeletionRequest $request, int $daysRemaining): void
            {
                if ($this->onRemind !== null) {
                    ($this->onRemind)($request, $daysRemaining);
                }

                $this->sent[] = [$request->subjectId, $daysRemaining];
            }
        };
    }

    #[Test]
    public function nothing_is_sent_while_the_deadline_is_far_off(): void
    {
        $schedule = $this->schedule();
        $schedule->request('user', '123');

        $this->clock->advance('6 days');  // 8 days remain
        $notifier = $this->notifier();

        $this->assertSame(0, $schedule->sendReminders($notifier)['sent']);
        $this->assertSame([], $notifier->sent);
    }

    #[Test]
    public function the_first_mark_fires_when_the_deadline_approaches(): void
    {
        $schedule = $this->schedule();
        $schedule->request('user', '123');

        $this->clock->advance('7 days');  // 7 days remain
        $notifier = $this->notifier();

        $this->assertSame(1, $schedule->sendReminders($notifier)['sent']);
        $this->assertSame([['123', 7]], $notifier->sent);
    }

    #[Test]
    public function a_daily_tick_never_sends_the_same_reminder_twice(): void
    {
        $schedule = $this->schedule();
        $schedule->request('user', '123');
        $this->clock->advance('7 days');

        $notifier = $this->notifier();

        // Seven consecutive daily runs, crossing both marks exactly once each.
        foreach (range(1, 7) as $_) {
            $schedule->sendReminders($notifier);
            $this->clock->advance('1 day');
        }

        $this->assertSame([['123', 7], ['123', 1]], $notifier->sent);
    }

    #[Test]
    public function a_missed_tick_still_sends_the_reminder_late(): void
    {
        $schedule = $this->schedule();
        $schedule->request('user', '123');

        // Scheduler down for a week; we are past the 7-day mark without it firing.
        $this->clock->advance('9 days');  // 5 days remain
        $notifier = $this->notifier();

        $schedule->sendReminders($notifier);

        $this->assertSame([['123', 5]], $notifier->sent, 'sent late rather than skipped');
    }

    #[Test]
    public function a_long_outage_sends_only_the_most_urgent_message(): void
    {
        $schedule = $this->schedule();
        $schedule->request('user', '123');

        $this->clock->advance('13 days');  // 1 day remains; both marks now apply
        $notifier = $this->notifier();

        $schedule->sendReminders($notifier);

        // Not "7 days left" followed by a correction.
        $this->assertSame([['123', 1]], $notifier->sent);

        // And both marks are recorded, so nothing fires again tomorrow.
        $this->clock->advance('1 day');
        $schedule->sendReminders($notifier);
        $this->assertCount(1, $notifier->sent);
    }

    #[Test]
    public function a_failed_send_is_retried_on_the_next_tick(): void
    {
        $schedule = $this->schedule();
        $schedule->request('user', '123');
        $this->clock->advance('7 days');

        $attempts = 0;
        $flaky = $this->notifier(function () use (&$attempts): void {
            $attempts++;

            if ($attempts === 1) {
                throw new \RuntimeException('mail transport down');
            }
        });

        $first = $schedule->sendReminders($flaky);

        $this->assertSame(1, $first['failed']);
        $this->assertSame('mail transport down', $first['errors']['123']);

        // The subject's last chance must not be lost to a transient failure.
        $second = $schedule->sendReminders($flaky);

        $this->assertSame(1, $second['sent']);
    }

    #[Test]
    public function one_failure_does_not_block_other_subjects(): void
    {
        $schedule = $this->schedule();
        $schedule->request('user', 'good-1');
        $schedule->request('user', 'broken');
        $schedule->request('user', 'good-2');
        $this->clock->advance('7 days');

        $notifier = $this->notifier(static function (DeletionRequest $r): void {
            if ($r->subjectId === 'broken') {
                throw new \RuntimeException('no email on file');
            }
        });

        $result = $schedule->sendReminders($notifier);

        $this->assertSame(2, $result['sent']);
        $this->assertSame(1, $result['failed']);
    }

    #[Test]
    public function a_reactivated_subject_stops_receiving_reminders(): void
    {
        $schedule = $this->schedule();
        $schedule->request('user', '123');
        $schedule->reactivate('user', '123');

        $this->clock->advance('7 days');
        $notifier = $this->notifier();

        $this->assertSame(0, $schedule->sendReminders($notifier)['sent']);
    }

    #[Test]
    public function configuring_no_marks_disables_reminders_entirely(): void
    {
        $schedule = $this->schedule(marks: []);
        $schedule->request('user', '123');
        $this->clock->advance('13 days');

        // Notably does not touch the notifier, so NullNotifier never throws.
        $this->assertSame(
            ['sent' => 0, 'failed' => 0, 'errors' => []],
            $schedule->sendReminders(new NullNotifier),
        );
    }

    #[Test]
    public function nonsense_marks_are_ignored(): void
    {
        $schedule = $this->schedule(marks: [7, 7, 0, -3, 1]);

        $this->assertSame([1, 7], $schedule->remindDays());
    }

    #[Test]
    public function the_null_notifier_refuses_to_pretend(): void
    {
        $schedule = $this->schedule();
        $schedule->request('user', '123');
        $this->clock->advance('7 days');

        $result = $schedule->sendReminders(new NullNotifier);

        $this->assertSame(1, $result['failed']);
        $this->assertStringContainsString('no SubjectNotifier is bound', $result['errors']['123']);
    }
}
