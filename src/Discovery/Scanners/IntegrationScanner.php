<?php

declare(strict_types=1);

namespace PrivacyCI\Discovery\Scanners;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PrivacyCI\Manifest\Integration;

/**
 * Detects the stores and third-party services an application talks to, from
 * config files and composer.lock.
 *
 * Marking a detected store `supported: false` is deliberate product design, not
 * an apology: printing "detected but not yet scannable: Snowflake" is how free
 * discovery shows which connectors are worth building next. Every one of
 * those lines is a user telling us what to build next, at zero research cost.
 */
final class IntegrationScanner
{
    /** Marker string => [kind, supported]. */
    private const MARKERS = [
        'redis' => ['Redis', true],
        'predis' => ['Redis', true],
        'phpredis' => ['Redis', true],
        'memcached' => ['Memcached', true],
        's3' => ['S3', true],
        'elasticsearch' => ['Elasticsearch', false],
        'opensearch' => ['OpenSearch', false],
        'algolia' => ['Algolia', false],
        'meilisearch' => ['Meilisearch', false],
        'typesense' => ['Typesense', false],
        'snowflake' => ['Snowflake', false],
        'bigquery' => ['BigQuery', false],
        'redshift' => ['Redshift', false],
        'clickhouse' => ['ClickHouse', false],
        'stripe' => ['Stripe', false],
        'paddle' => ['Paddle', false],
        'mailgun' => ['Mailgun', false],
        'postmark' => ['Postmark', false],
        'sendgrid' => ['SendGrid', false],
        'ses' => ['Amazon SES', false],
        'intercom' => ['Intercom', false],
        'iterable' => ['Iterable', false],
        'segment' => ['Segment', false],
        'mixpanel' => ['Mixpanel', false],
        'amplitude' => ['Amplitude', false],
        'zendesk' => ['Zendesk', false],
        'hubspot' => ['HubSpot', false],
        'twilio' => ['Twilio', false],
        'sentry' => ['Sentry', false],
    ];

    /** Composer package => kind. Stronger evidence than a config string. */
    private const PACKAGES = [
        'laravel/scout' => 'Laravel Scout',
        'predis/predis' => 'Redis',
        'aws/aws-sdk-php' => 'AWS',
        'league/flysystem-aws-s3-v3' => 'S3',
        'stripe/stripe-php' => 'Stripe',
        'laravel/cashier' => 'Stripe',
        'algolia/algoliasearch-client-php' => 'Algolia',
        'meilisearch/meilisearch-php' => 'Meilisearch',
        'elasticsearch/elasticsearch' => 'Elasticsearch',
        'google/cloud-bigquery' => 'BigQuery',
        'sentry/sentry-laravel' => 'Sentry',
    ];

    private readonly Parser $parser;

    private readonly NodeFinder $finder;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
        $this->finder = new NodeFinder;
    }

    /**
     * @param  list<string>  $configPaths
     * @return list<Integration>
     */
    public function scan(array $configPaths, ?string $composerLock = null): array
    {
        /** @var array<string, Integration> $found */
        $found = [];

        foreach ($configPaths as $path) {
            foreach ((array) glob(rtrim($path, '/').'/*.php') as $file) {
                if (! is_string($file)) {
                    continue;
                }

                foreach ($this->declarationsIn($file) as $literal) {
                    $marker = strtolower($literal);

                    if (isset(self::MARKERS[$marker])) {
                        [$kind, $supported] = self::MARKERS[$marker];
                        $found[$kind] ??= new Integration($kind, 'config/'.basename($file), $supported);
                    }
                }
            }
        }

        if ($composerLock !== null && is_file($composerLock)) {
            foreach ($this->lockPackages($composerLock) as $package) {
                if (isset(self::PACKAGES[$package])) {
                    $kind = self::PACKAGES[$package];
                    $supported = self::MARKERS[strtolower($kind)][1] ?? false;
                    $found[$kind] = new Integration($kind, $package, $supported);
                }
            }
        }

        $result = array_values($found);
        usort($result, static fn (Integration $a, Integration $b): int => $a->kind <=> $b->kind);

        return $result;
    }

    /**
     * Keys under which a string names the thing being configured.
     *
     * @var list<string>
     */
    private const DRIVER_KEYS = [
        'driver', 'client', 'connection', 'default', 'adapter', 'engine', 'provider', 'store',
    ];

    /**
     * Strings that are *declaring* a store, not merely mentioning one.
     *
     * A config array key (`'snowflake' => [...]`) declares one. A value under
     * `driver` declares one. A bare item in a list does not, an icon named
     * "snowflake" is not a data warehouse, and telling a team they run
     * infrastructure they do not run is the sort of false positive that makes
     * people stop believing the whole report.
     *
     * @return list<string>
     */
    private function declarationsIn(string $file): array
    {
        $code = @file_get_contents($file);

        if ($code === false) {
            return [];
        }

        try {
            $ast = $this->parser->parse($code);
        } catch (\Throwable) {
            return [];
        }

        if ($ast === null) {
            return [];
        }

        /** @var list<Node\Expr\ArrayItem> $items */
        $items = $this->finder->findInstanceOf($ast, Node\Expr\ArrayItem::class);

        $found = [];

        foreach ($items as $item) {
            if (! $item->key instanceof Node\Scalar\String_) {
                continue;
            }

            $key = $item->key->value;
            $found[] = $key;

            if (in_array(strtolower($key), self::DRIVER_KEYS, true)
                && $item->value instanceof Node\Scalar\String_) {
                $found[] = $item->value->value;
            }
        }

        return $found;
    }

    /** @return list<string> */
    private function lockPackages(string $lockFile): array
    {
        $raw = @file_get_contents($lockFile);

        if ($raw === false) {
            return [];
        }

        try {
            /** @var array{packages?: list<array{name?: string}>} $data */
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        $names = [];

        foreach ($data['packages'] ?? [] as $package) {
            if (isset($package['name'])) {
                $names[] = $package['name'];
            }
        }

        return $names;
    }
}
