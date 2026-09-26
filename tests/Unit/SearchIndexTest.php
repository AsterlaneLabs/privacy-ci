<?php

declare(strict_types=1);

namespace PrivacyCI\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PrivacyCI\Discovery\Discoverer;
use PrivacyCI\Discovery\Scanners\IntegrationScanner;
use PrivacyCI\Discovery\Scanners\ModelScanner;
use PrivacyCI\Generation\HandlerGenerator;
use PrivacyCI\Generation\HandlerPlanner;
use PrivacyCI\Manifest\Classification;
use PrivacyCI\Manifest\Location;
use PrivacyCI\Manifest\LocationKind;
use PrivacyCI\Manifest\Manifest;
use PrivacyCI\Manifest\SearchTarget;
use PrivacyCI\Manifest\Subject;
use PrivacyCI\Policy\PolicyCompiler;
use PrivacyCI\Policy\PrivacyPolicy;
use PrivacyCI\Search\ClientSearchIndex;
use PrivacyCI\Verification\Expectation;
use PrivacyCI\Verification\FootprintResolver;
use PrivacyCI\Verification\Outcome;
use PrivacyCI\Verification\Probes\SearchProbe;
use PrivacyCI\Verification\Verifier;

final class SearchIndexTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../fixtures';

    private Subject $subject;

    protected function setUp(): void
    {
        $this->subject = new Subject('user', 'users.id');
    }

    // ---------------------------------------------------------------- target

    #[Test]
    public function a_bare_index_name_addresses_nobody(): void
    {
        $target = SearchTarget::parse('users');

        $this->assertTrue($target->isWholeIndex());
        $this->assertFalse($target->isDocument());
        $this->assertFalse($target->isQuery());
    }

    #[Test]
    public function the_document_and_query_forms_round_trip(): void
    {
        foreach (['users/{id}', 'comments?user_id={id}', 'orders'] as $path) {
            $this->assertSame($path, SearchTarget::parse($path)->path());
        }
    }

    #[Test]
    public function a_query_target_names_its_field_and_value(): void
    {
        $target = SearchTarget::parse('comments?user_id={id}');

        $this->assertSame('comments', $target->index);
        $this->assertSame('user_id', $target->field);
        $this->assertSame('{id}', $target->value);
        $this->assertSame('comments where user_id = {id}', $target->describe());
    }

    // --------------------------------------------------------------- scanner

    #[Test]
    public function it_reads_scouts_trait_and_index_name(): void
    {
        $models = (new ModelScanner)->scan([self::FIXTURES.'/Models']);

        $user = $models->forTable('users');
        $this->assertNotNull($user);
        $this->assertTrue($user->searchable);
        $this->assertSame('users', $user->searchIndex(), 'searchableAs() defaults to the table');
        $this->assertSame(['email', 'name'], $user->searchableFields);

        $comment = $models->forTable('comments');
        $this->assertNotNull($comment);
        $this->assertSame('comments_index', $comment->searchIndex());
    }

    #[Test]
    public function a_model_extending_a_searchable_base_is_searchable(): void
    {
        $models = (new ModelScanner)->scan([self::FIXTURES.'/ScoutModels']);

        $ticket = $models->forTable('tickets');

        $this->assertNotNull($ticket, 'Ticket should be scanned');
        $this->assertTrue($ticket->searchable, 'the trait is on its base class');
        // searchableAs() is inherited only when the base names one; Scout
        // otherwise falls back to the concrete model's own table.
        $this->assertSame('tickets', $ticket->searchIndex());
    }

    #[Test]
    public function an_applications_own_searchable_trait_is_not_scouts(): void
    {
        // A finding from the trait is deterministic and can fail a build, so
        // matching on the short name would let an unrelated trait block a deploy.
        $models = (new ModelScanner)->scan([self::FIXTURES.'/ScoutModels']);

        $note = $models->forTable('notes');

        $this->assertNotNull($note);
        $this->assertFalse($note->searchable);
        $this->assertNull($note->searchIndex());
    }

    // ------------------------------------------------------------- discovery

    /** @return list<Location> */
    private function searchLocations(?string $lock = null): array
    {
        $manifest = (new Discoverer)->discover(
            project: 'fixture/app',
            migrationPaths: [self::FIXTURES.'/migrations'],
            composerLock: $lock,
            subject: $this->subject,
            modelPaths: [self::FIXTURES.'/Models'],
        );

        return array_values(array_filter(
            $manifest->locations(),
            static fn (Location $l): bool => $l->kind === LocationKind::SearchIndex,
        ));
    }

    #[Test]
    public function the_subject_is_the_document_in_its_own_index(): void
    {
        $paths = array_map(static fn (Location $l): string => $l->path, $this->searchLocations());

        $this->assertContains('users/{id}', $paths);
    }

    #[Test]
    public function a_related_model_is_addressed_by_query_not_by_document_id(): void
    {
        // A comment's document id is the comment's id. Addressing it as
        // comments_index/{id} would delete whichever comment happened to share
        // the user's id, which is a data-loss bug wearing a passing test.
        $paths = array_map(static fn (Location $l): string => $l->path, $this->searchLocations());

        $this->assertContains('comments_index?user_id={id}', $paths);
        $this->assertNotContains('comments_index/{id}', $paths);
    }

    #[Test]
    public function an_unclassified_index_can_fail_a_build(): void
    {
        foreach ($this->searchLocations() as $location) {
            $this->assertTrue(
                $location->linkage->isDeterministic(),
                "{$location->path} came from a trait, not a guess",
            );
            $this->assertTrue($location->blocksBuild());
        }
    }

    #[Test]
    public function the_installed_driver_names_the_cluster(): void
    {
        // Scout is an abstraction over engines, so `use Searchable` says a model
        // is indexed and not where. The driver in composer.lock is the only thing
        // in the repository that answers that.
        foreach ($this->searchLocations(self::FIXTURES.'/search-app/elasticsearch.lock') as $location) {
            $this->assertSame('elasticsearch', $location->store);
        }

        foreach ($this->searchLocations(self::FIXTURES.'/search-app/opensearch.lock') as $location) {
            $this->assertSame('opensearch', $location->store);
        }
    }

    #[Test]
    public function two_clients_at_once_is_not_guessed_at(): void
    {
        $scanner = new IntegrationScanner;

        $this->assertNull(IntegrationScanner::searchConnection(
            $scanner->scan([], self::FIXTURES.'/search-app/both.lock'),
        ));

        foreach ($this->searchLocations(self::FIXTURES.'/search-app/both.lock') as $location) {
            $this->assertSame('scout', $location->store);
        }
    }

    #[Test]
    public function elasticsearch_and_opensearch_are_scannable(): void
    {
        $integrations = (new IntegrationScanner)->scan([], self::FIXTURES.'/search-app/both.lock');

        foreach ($integrations as $integration) {
            if (in_array($integration->kind, ['Elasticsearch', 'OpenSearch'], true)) {
                $this->assertTrue($integration->supported);
            }
        }
    }

    // ---------------------------------------------------------------- policy

    #[Test]
    public function a_bare_index_in_a_policy_means_the_subject_is_the_document(): void
    {
        $manifest = $this->compiled(new class extends PrivacyPolicy
        {
            public function configure(): void
            {
                $this->deleteSearch('users');
                $this->deleteSearch('comments', by: 'user_id');
                $this->deleteSearch('posts/{id}', 'opensearch');
            }
        });

        $paths = array_map(static fn (Location $l): string => $l->path, $manifest->locations());
        sort($paths);

        $this->assertSame(['comments?user_id={id}', 'posts/{id}', 'users/{id}'], $paths);
    }

    #[Test]
    public function an_unstated_connection_does_not_erase_a_detected_one(): void
    {
        // deleteSearch('users') says nothing about which cluster. Letting the
        // argument's default flatten a store read from composer.lock lost the
        // answer *and* claimed the policy had named it.
        $discovered = new Manifest(
            project: 'x',
            subjects: [$this->subject],
            locations: [new Location(
                id: 'search:opensearch:users/{id}',
                kind: LocationKind::SearchIndex,
                store: 'opensearch',
                path: 'users/{id}',
                subject: 'user',
                linkage: \PrivacyCI\Manifest\Linkage::SubjectRoot,
                confidence: 1.0,
                evidence: ['User uses Laravel\\Scout\\Searchable'],
            )],
        );

        $classified = (new PolicyCompiler)->apply($discovered, new class extends PrivacyPolicy
        {
            public function configure(): void
            {
                $this->deleteSearch('users');
            }
        })->locations()[0];

        $this->assertSame('opensearch', $classified->store);
        $this->assertSame(Classification::Delete, $classified->classification);
        $this->assertNotContains('store named by policy: default', $classified->evidence);
    }

    #[Test]
    public function a_stated_connection_still_wins(): void
    {
        $discovered = new Manifest(
            project: 'x',
            subjects: [$this->subject],
            locations: [new Location(
                id: 'search:scout:users/{id}',
                kind: LocationKind::SearchIndex,
                store: 'scout',
                path: 'users/{id}',
                subject: 'user',
                linkage: \PrivacyCI\Manifest\Linkage::SubjectRoot,
                confidence: 1.0,
            )],
        );

        $classified = (new PolicyCompiler)->apply($discovered, new class extends PrivacyPolicy
        {
            public function configure(): void
            {
                $this->deleteSearch('users', 'elasticsearch');
            }
        })->locations()[0];

        $this->assertSame('elasticsearch', $classified->store);
        $this->assertContains('store named by policy: elasticsearch', $classified->evidence);
    }

    #[Test]
    public function the_connection_stays_in_second_position(): void
    {
        // Existing positional calls have to keep working; `by:` is named.
        $manifest = $this->compiled(new class extends PrivacyPolicy
        {
            public function configure(): void
            {
                $this->deleteSearch('posts', 'opensearch');
            }
        });

        $this->assertSame('opensearch', $manifest->locations()[0]->store);
    }

    // -------------------------------------------------------------- handlers

    #[Test]
    public function a_search_index_is_not_cleared_with_redis(): void
    {
        // The regression this whole path exists for: with no branch of its own,
        // a search target fell through to the key-value default and generated
        // Redis::del("users_index") against an Elasticsearch cluster.
        $code = $this->generate(new class extends PrivacyPolicy
        {
            public function configure(): void
            {
                $this->deleteSearch('users');
                $this->deleteSearch('comments', by: 'user_id');
            }
        });

        $this->assertStringNotContainsString('Redis', $code);
        $this->assertStringContainsString('use PrivacyCI\Search\SearchIndex;', $code);
        $this->assertStringContainsString(
            "app(SearchIndex::class)->deleteDocument('users', (string) \$subjectId);",
            $code,
        );
        $this->assertStringContainsString(
            "app(SearchIndex::class)->deleteByQuery('comments', 'user_id', (string) \$subjectId);",
            $code,
        );
    }

    #[Test]
    public function a_non_default_connection_is_passed_through(): void
    {
        $code = $this->generate(new class extends PrivacyPolicy
        {
            public function configure(): void
            {
                $this->deleteSearch('posts', 'opensearch');
            }
        });

        $this->assertStringContainsString(
            "app(SearchIndex::class)->deleteDocument('posts', (string) \$subjectId, 'opensearch');",
            $code,
        );
    }

    #[Test]
    public function an_index_with_no_addressable_subject_becomes_a_todo(): void
    {
        $manifest = new Manifest(
            project: 'x',
            subjects: [$this->subject],
            locations: [new Location(
                id: 'search:default:orders',
                kind: LocationKind::SearchIndex,
                store: 'default',
                path: 'orders',
                subject: 'user',
                linkage: \PrivacyCI\Manifest\Linkage::ForeignKey,
                confidence: 1.0,
                classification: Classification::Delete,
            )],
        );

        $plan = (new HandlerPlanner)->plan($manifest, $this->subject);
        $code = (new HandlerGenerator)->generate($plan);

        $this->assertStringContainsString('TODO: orders', $code);
        $this->assertStringNotContainsString('deleteDocument', $code);
        $this->assertStringNotContainsString('deleteByQuery', $code);
    }

    // ---------------------------------------------------------- verification

    #[Test]
    public function the_document_form_resolves_to_an_index_and_an_id(): void
    {
        $address = $this->addressFor('users/{id}');

        $this->assertSame(Expectation::Absent, $address->expectation);
        $this->assertSame(['index' => 'users', 'id' => '99'], $address->locator);
        $this->assertSame('users/99', $address->describe);
    }

    #[Test]
    public function the_query_form_resolves_to_a_field_and_a_value(): void
    {
        $address = $this->addressFor('comments?user_id={id}');

        $this->assertSame(
            ['index' => 'comments', 'field' => 'user_id', 'value' => '99'],
            $address->locator,
        );
        $this->assertSame('comments where user_id = 99', $address->describe);
    }

    #[Test]
    public function an_index_name_alone_is_never_verifiable(): void
    {
        // Asking whether the `users` index exists would pass for every subject
        // forever, which is the one answer a verification report must not give.
        $address = $this->addressFor('orders');

        $this->assertSame(Expectation::Unverifiable, $address->expectation);
        $this->assertStringContainsString('nothing to look up', (string) $address->reason);
    }

    #[Test]
    public function a_document_still_present_is_a_failure(): void
    {
        $result = $this->verify('users/{id}', $this->client(['exists' => true]));

        $this->assertSame(Outcome::Fail, $result->results[0]->outcome);
    }

    #[Test]
    public function a_document_that_is_gone_passes(): void
    {
        $result = $this->verify('users/{id}', $this->client(['exists' => false]));

        $this->assertSame(Outcome::Pass, $result->results[0]->outcome);
    }

    #[Test]
    public function matching_documents_are_a_failure(): void
    {
        $result = $this->verify('comments?user_id={id}', $this->client(['count' => ['count' => 3]]));

        $this->assertSame(Outcome::Fail, $result->results[0]->outcome);
    }

    #[Test]
    public function a_cluster_we_could_not_reach_is_unchecked_not_passed(): void
    {
        $client = new class
        {
            public function exists(array $params): bool
            {
                throw new \RuntimeException('Connection refused');
            }
        };

        $result = $this->verify('users/{id}', $client);

        $this->assertSame(Outcome::Unchecked, $result->results[0]->outcome);
        $this->assertStringContainsString('Connection refused', (string) $result->results[0]->detail);
    }

    #[Test]
    public function no_configured_client_is_unchecked_not_passed(): void
    {
        $probe = new SearchProbe(new ClientSearchIndex(static fn (string $c): ?object => null));
        $footprint = (new FootprintResolver)->resolve(
            $this->manifestWith('users/{id}'),
            $this->subject,
            '99',
        );

        $result = (new Verifier([$probe]))->verify($footprint);

        $this->assertSame(Outcome::Unchecked, $result->results[0]->outcome);
        $this->assertStringContainsString('no search client configured', (string) $result->results[0]->detail);
    }

    #[Test]
    public function elasticsearchs_response_object_is_understood_too(): void
    {
        // OpenSearch answers exists() with a bool, Elasticsearch 8 with a
        // response object. Both have to mean the same thing here.
        $client = new class
        {
            public function exists(array $params): object
            {
                return new class
                {
                    public function asBool(): bool
                    {
                        return true;
                    }
                };
            }
        };

        $result = $this->verify('users/{id}', $client);

        $this->assertSame(Outcome::Fail, $result->results[0]->outcome);
    }

    #[Test]
    public function a_missing_index_holds_nobody(): void
    {
        $client = new class
        {
            public function count(array $params): array
            {
                throw new \RuntimeException('index_not_found_exception', 404);
            }
        };

        $result = $this->verify('comments?user_id={id}', $client);

        $this->assertSame(Outcome::Pass, $result->results[0]->outcome);
    }

    // --------------------------------------------------------------- helpers

    private function compiled(PrivacyPolicy $policy): Manifest
    {
        return (new PolicyCompiler)->apply(
            new Manifest(project: 'x', subjects: [$this->subject], locations: []),
            $policy,
        );
    }

    private function generate(PrivacyPolicy $policy): string
    {
        return (new HandlerGenerator)->generate(
            (new HandlerPlanner)->plan($this->compiled($policy), $this->subject),
        );
    }

    private function manifestWith(string $path, string $store = 'default'): Manifest
    {
        return new Manifest(
            project: 'x',
            subjects: [$this->subject],
            locations: [new Location(
                id: Location::idFor(LocationKind::SearchIndex, $store, $path),
                kind: LocationKind::SearchIndex,
                store: $store,
                path: $path,
                subject: 'user',
                linkage: \PrivacyCI\Manifest\Linkage::Declared,
                confidence: 1.0,
                classification: Classification::Delete,
            )],
        );
    }

    private function addressFor(string $path): \PrivacyCI\Verification\Address
    {
        return (new FootprintResolver)
            ->resolve($this->manifestWith($path), $this->subject, '99')
            ->addresses[0];
    }

    private function verify(string $path, object $client): \PrivacyCI\Verification\VerificationResult
    {
        $probe = new SearchProbe(new ClientSearchIndex(static fn (string $c): object => $client));
        $footprint = (new FootprintResolver)->resolve($this->manifestWith($path), $this->subject, '99');

        return (new Verifier([$probe]))->verify($footprint);
    }

    /** @param array<string, mixed> $returns */
    private function client(array $returns): object
    {
        return new class($returns)
        {
            /** @param array<string, mixed> $returns */
            public function __construct(private readonly array $returns)
            {
            }

            public function exists(array $params): mixed
            {
                return $this->returns['exists'] ?? false;
            }

            public function count(array $params): mixed
            {
                return $this->returns['count'] ?? ['count' => 0];
            }
        };
    }
}
