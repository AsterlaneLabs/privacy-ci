<?php

declare(strict_types=1);

namespace PrivacyCI\Tests\Feature;

use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PrivacyCI\PrivacyCIServiceProvider;

/**
 * Routes are registered during boot, so this needs its own application instance
 * rather than flipping config on an already-booted one.
 */
final class ReactivationDisabledTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PrivacyCIServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('privacy.reactivation.enabled', false);
        $app['config']->set('privacy.lifecycle.schedule', null);
    }

    #[Test]
    public function disabling_the_flow_registers_no_routes(): void
    {
        $this->assertFalse($this->app['router']->has('privacy.reactivate'));
    }
}
