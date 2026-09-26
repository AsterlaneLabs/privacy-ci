<?php

declare(strict_types=1);

namespace PrivacyCI\Tests\Feature;

use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PrivacyCI\PrivacyCIServiceProvider;
use PrivacyCI\Search\SearchIndex;
use PrivacyCI\Verification\Probes\SearchProbe;
use PrivacyCI\Verification\Verifier;

/**
 * The container wiring, which the unit tests construct around.
 *
 * SearchProbe is only useful if it can actually be resolved from the probe list
 * in config, and the whole point of registering it there is that nobody has to
 * think about it.
 */
final class SearchBindingTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PrivacyCIServiceProvider::class];
    }

    #[Test]
    public function the_search_probe_resolves_from_the_default_config(): void
    {
        $this->assertContains(
            SearchProbe::class,
            (array) config('privacy.verification.probes'),
        );

        $this->assertInstanceOf(Verifier::class, $this->app->make(Verifier::class));
        $this->assertInstanceOf(SearchProbe::class, $this->app->make(SearchProbe::class));
    }

    #[Test]
    public function an_unconfigured_cluster_is_never_connected_to(): void
    {
        // Resolving the binding must not reach for a client. `privacy:check`
        // runs in CI with no search credentials present, and a connection
        // attempt at registration time would break it for everyone.
        $this->assertInstanceOf(SearchIndex::class, $this->app->make(SearchIndex::class));
    }

    #[Test]
    public function a_closure_client_is_resolved_lazily(): void
    {
        $client = new class
        {
            public bool $asked = false;

            public function exists(array $params): bool
            {
                $this->asked = true;

                return true;
            }
        };

        $calls = 0;

        config(['privacy.search.clients' => [
            'default' => function () use ($client, &$calls): object {
                $calls++;

                return $client;
            },
        ]]);

        $search = $this->app->make(SearchIndex::class);

        $this->assertSame(0, $calls, 'no client until something asks a question');

        $this->assertTrue($search->exists('users', '42'));
        $this->assertTrue($client->asked);
        $this->assertSame(1, $calls);

        // Second question, same connection: resolved once and kept.
        $search->exists('users', '43');
        $this->assertSame(1, $calls);
    }

    #[Test]
    public function an_application_can_bind_its_own_implementation(): void
    {
        $own = new class implements SearchIndex
        {
            public function exists(string $index, string $id, string $connection = 'default'): bool
            {
                return false;
            }

            public function count(string $index, string $field, string $value, string $connection = 'default'): int
            {
                return 0;
            }

            public function deleteDocument(string $index, string $id, string $connection = 'default'): void
            {
            }

            public function deleteByQuery(string $index, string $field, string $value, string $connection = 'default'): int
            {
                return 0;
            }
        };

        $this->app->instance(SearchIndex::class, $own);

        $this->assertSame($own, $this->app->make(SearchIndex::class));
    }
}
