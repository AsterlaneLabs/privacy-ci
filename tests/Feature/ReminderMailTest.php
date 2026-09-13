<?php

declare(strict_types=1);

namespace PrivacyCI\Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PrivacyCI\Lifecycle\DeletionSchedule;
use PrivacyCI\Mail\DeletionReminderMail;
use PrivacyCI\PrivacyCIServiceProvider;

final class ReminderMailTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PrivacyCIServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('privacy.lifecycle.mode', 'hold');
        $app['config']->set('privacy.lifecycle.grace_days', 14);
        $app['config']->set('privacy.lifecycle.remind_days', [7, 1]);
        $app['config']->set('privacy.lifecycle.notifier', RecordingNotifier::class);
        $app['config']->set('privacy.lifecycle.schedule', null);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();
        RecordingNotifier::$reminded = [];
    }

    private function schedule(): DeletionSchedule
    {
        return $this->app->make(DeletionSchedule::class);
    }

    #[Test]
    public function the_command_sends_a_reminder_when_the_deadline_nears(): void
    {
        Mail::fake();
        $this->schedule()->request('user', '123');

        $this->travel(7)->days();

        $this->artisan('privacy:process-deletions')
            ->expectsOutputToContain('Sent 1 reminder')
            ->assertSuccessful();

        $this->assertSame([['123', 7]], RecordingNotifier::$reminded);
        Mail::assertSent(DeletionReminderMail::class);
    }

    #[Test]
    public function running_the_command_daily_does_not_resend(): void
    {
        Mail::fake();
        $this->schedule()->request('user', '123');
        $this->travel(7)->days();

        // Days 7 through 13, the 1-day mark only becomes applicable on day 13.
        foreach (range(1, 7) as $_) {
            $this->artisan('privacy:process-deletions')->assertSuccessful();
            $this->travel(1)->days();
        }

        // Marks 7 and 1 exactly once each across seven daily runs.
        $this->assertSame([['123', 7], ['123', 1]], RecordingNotifier::$reminded);
        Mail::assertSentCount(2);
    }

    #[Test]
    public function reminder_state_survives_the_database_round_trip(): void
    {
        Mail::fake();
        $request = $this->schedule()->request('user', '123');
        $this->travel(7)->days();

        $this->artisan('privacy:process-deletions')->assertSuccessful();

        $stored = $this->app->make(\PrivacyCI\Lifecycle\DeletionStore::class)->find($request->id);

        $this->assertSame([7], $stored?->remindersSent, 'reminders_sent must persist');
    }

    #[Test]
    public function the_urgent_reminder_reads_differently(): void
    {
        Mail::fake();
        $request = $this->schedule()->request('user', '123');
        $this->travel(13)->days();

        Mail::to('subject@example.com')->send(new DeletionReminderMail($request, 1));

        Mail::assertSent(DeletionReminderMail::class, function (DeletionReminderMail $mail): bool {
            return str_contains($mail->envelope()->subject, 'Last chance')
                && str_contains($mail->render(), 'deleted tomorrow')
                && str_contains($mail->render(), 'Keep my account');
        });
    }

    #[Test]
    public function skip_reminders_suppresses_them(): void
    {
        Mail::fake();
        $this->schedule()->request('user', '123');
        $this->travel(7)->days();

        $this->artisan('privacy:process-deletions --skip-reminders')->assertSuccessful();

        $this->assertSame([], RecordingNotifier::$reminded);
        Mail::assertNothingSent();
    }

    #[Test]
    public function a_dry_run_sends_nothing(): void
    {
        Mail::fake();
        $this->schedule()->request('user', '123');
        $this->travel(7)->days();

        $this->artisan('privacy:process-deletions --dry-run')->assertSuccessful();

        $this->assertSame([], RecordingNotifier::$reminded);
        Mail::assertNothingSent();
    }

    #[Test]
    public function the_reminder_link_is_the_same_signed_reactivation_url(): void
    {
        Mail::fake();
        $request = $this->schedule()->request('user', '123');

        Mail::to('subject@example.com')->send(new DeletionReminderMail($request, 1));

        Mail::assertSent(DeletionReminderMail::class, function (DeletionReminderMail $mail): bool {
            return str_contains($mail->render(), 'privacy/reactivate/'.$mail->deletionRequest->id)
                && str_contains($mail->render(), 'signature=');
        });
    }
}
