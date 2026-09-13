<?php

declare(strict_types=1);

namespace PrivacyCI\Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PrivacyCI\Lifecycle\DeletionRequest;
use PrivacyCI\Lifecycle\DeletionSchedule;
use PrivacyCI\Lifecycle\DeletionStatus;
use PrivacyCI\Lifecycle\DeletionStore;
use PrivacyCI\Lifecycle\Laravel\ReactivationLink;
use PrivacyCI\Lifecycle\SubjectSuspender;
use PrivacyCI\Mail\DeletionScheduledMail;
use PrivacyCI\PrivacyCIServiceProvider;

final class ReactivationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PrivacyCIServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('privacy.lifecycle.mode', 'suspend');
        $app['config']->set('privacy.lifecycle.grace_days', 14);
        $app['config']->set('privacy.lifecycle.suspender', RecordingSuspender::class);
        $app['config']->set('privacy.lifecycle.schedule', null);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    private function schedule(): DeletionSchedule
    {
        return $this->app->make(DeletionSchedule::class);
    }

    private function link(DeletionRequest $request): string
    {
        return $this->app->make(ReactivationLink::class)->for($request);
    }

    #[Test]
    public function the_link_shows_a_confirmation_page_without_changing_anything(): void
    {
        $request = $this->schedule()->request('user', '123');

        $this->get($this->link($request))
            ->assertOk()
            ->assertSee('Reactivate your account?')
            ->assertSee($request->executeAfter->format('j F Y'));

        // A mail scanner following the link must not have rescued the account.
        $this->assertNotNull($this->schedule()->pendingFor('user', '123'));
    }

    #[Test]
    public function posting_reactivates_the_subject(): void
    {
        $request = $this->schedule()->request('user', '123');

        $this->post($this->link($request))
            ->assertOk()
            ->assertSee('Your account is back');

        $this->assertNull($this->schedule()->pendingFor('user', '123'));

        $stored = $this->app->make(DeletionStore::class)->find($request->id);
        $this->assertSame(DeletionStatus::Cancelled, $stored?->status);
        $this->assertContains('123', RecordingSuspender::$reactivated);
    }

    #[Test]
    public function an_unsigned_url_is_rejected(): void
    {
        $request = $this->schedule()->request('user', '123');

        $this->get(url('privacy/reactivate/'.$request->id))->assertForbidden();
        $this->assertNotNull($this->schedule()->pendingFor('user', '123'));
    }

    #[Test]
    public function a_tampered_signature_is_rejected(): void
    {
        $first = $this->schedule()->request('user', '111');
        $second = $this->schedule()->request('user', '222');

        // Swap the id but keep the other request's signature.
        $forged = str_replace($first->id, $second->id, $this->link($first));

        $this->get($forged)->assertForbidden();
        $this->assertNotNull($this->schedule()->pendingFor('user', '222'));
    }

    #[Test]
    public function the_link_dies_with_the_grace_period(): void
    {
        $request = $this->schedule()->request('user', '123');
        $url = $this->link($request);

        $this->travelTo($request->executeAfter->modify('+1 second'));

        $this->get($url)->assertForbidden();
    }

    #[Test]
    public function following_the_link_twice_does_not_error(): void
    {
        $request = $this->schedule()->request('user', '123');
        $url = $this->link($request);

        $this->post($url)->assertOk()->assertSee('Your account is back');
        $this->post($url)->assertOk()->assertSee('already active');
    }

    #[Test]
    public function a_link_for_an_unknown_request_is_handled_gracefully(): void
    {
        $url = URL::temporarySignedRoute(
            'privacy.reactivate',
            now()->addDay(),
            ['request' => str_repeat('a', 32)],
        );

        $this->get($url)->assertOk()->assertSee('no longer valid');
    }

    #[Test]
    public function the_email_carries_a_working_link(): void
    {
        Mail::fake();

        $request = $this->schedule()->request('user', '123', via: 'account settings');

        Mail::to('someone@example.com')->send(new DeletionScheduledMail($request));

        Mail::assertSent(DeletionScheduledMail::class, function (DeletionScheduledMail $mail) use ($request): bool {
            $rendered = $mail->render();

            return str_contains($rendered, 'Reactivate my account')
                && str_contains($rendered, $request->executeAfter->format('j F Y'));
        });
    }

}
