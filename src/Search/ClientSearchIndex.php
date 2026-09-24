<?php

declare(strict_types=1);

namespace PrivacyCI\Search;

/**
 * Speaks to an Elasticsearch or OpenSearch client without depending on either.
 *
 * The two SDKs share an ancestor and still differ where it matters: OpenSearch's
 * `exists()` returns a bool, Elasticsearch 8 returns a response object whose
 * `asBool()` carries the answer, and `count()` comes back as an array from one
 * and an ArrayAccess response from the other. Requiring one SDK would make this
 * package unusable by half its audience, and `instanceof` against a class that
 * may not be installed is a fatal error, so the shapes are normalised by hand.
 *
 * The client itself is resolved lazily, per connection: a cluster nobody probes
 * is never connected to, which is what lets `privacy:check` keep running in CI
 * with no credentials present.
 */
final class ClientSearchIndex implements SearchIndex
{
    /** Connection names that all mean "the one configured client". */
    private const DEFAULT_CONNECTIONS = ['default', 'scout', 'elasticsearch', 'opensearch'];

    /** @var array<string, object> */
    private array $resolved = [];

    /** @param  callable(string): ?object  $clients  Connection name => SDK client. */
    public function __construct(private readonly mixed $clients)
    {
    }

    public function exists(string $index, string $id, string $connection = 'default'): bool
    {
        try {
            $response = $this->client($connection)->exists(['index' => $index, 'id' => $id]);
        } catch (\Throwable $e) {
            // A missing document is the answer to the question, not a failure.
            // Anything else has to surface: a cluster we could not reach must
            // never be reported as a subject successfully erased.
            if ($this->isNotFound($e)) {
                return false;
            }

            throw $e;
        }

        return $this->asBool($response);
    }

    public function count(string $index, string $field, string $value, string $connection = 'default'): int
    {
        try {
            $response = $this->client($connection)->count([
                'index' => $index,
                'body' => $this->query($field, $value),
            ]);
        } catch (\Throwable $e) {
            // An index that does not exist holds nobody. An index we could not
            // read is a different thing entirely and stays an error.
            if ($this->isNotFound($e)) {
                return 0;
            }

            throw $e;
        }

        return (int) ($this->asArray($response)['count'] ?? 0);
    }

    public function deleteDocument(string $index, string $id, string $connection = 'default'): void
    {
        try {
            $this->client($connection)->delete([
                'index' => $index,
                'id' => $id,
                // Erasure has to be visible to the verification that follows it.
                // Without this the index is near-real-time and a check moments
                // later reports the subject still present.
                'refresh' => 'true',
            ]);
        } catch (\Throwable $e) {
            if (! $this->isNotFound($e)) {
                throw $e;
            }
        }
    }

    public function deleteByQuery(string $index, string $field, string $value, string $connection = 'default'): int
    {
        try {
            $response = $this->client($connection)->deleteByQuery([
                'index' => $index,
                'body' => $this->query($field, $value),
                'refresh' => 'true',
            ]);
        } catch (\Throwable $e) {
            if ($this->isNotFound($e)) {
                return 0;
            }

            throw $e;
        }

        return (int) ($this->asArray($response)['deleted'] ?? 0);
    }

    /**
     * A term query, which matches the field exactly.
     *
     * Right for the ids this is ever pointed at, and wrong for an analysed text
     * field, where the value would have been broken into tokens at index time.
     * Nothing here should be querying a text field for a person: the field named
     * by a policy or discovered from a foreign key is always an id.
     *
     * @return array{query: array{term: array<string, string>}}
     */
    private function query(string $field, string $value): array
    {
        return ['query' => ['term' => [$field => $value]]];
    }

    private function client(string $connection): object
    {
        $key = in_array($connection, self::DEFAULT_CONNECTIONS, true) ? 'default' : $connection;

        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $client = ($this->clients)($key);

        if (! is_object($client)) {
            throw new \RuntimeException(sprintf(
                'no search client configured for connection "%s"; set privacy.search.clients',
                $connection,
            ));
        }

        return $this->resolved[$key] = $client;
    }

    /** OpenSearch answers with a bool; Elasticsearch 8 with a response object. */
    private function asBool(mixed $response): bool
    {
        if (is_bool($response)) {
            return $response;
        }

        if (is_object($response) && method_exists($response, 'asBool')) {
            return (bool) $response->asBool();
        }

        if (is_object($response) && method_exists($response, 'getStatusCode')) {
            return $response->getStatusCode() === 200;
        }

        return (bool) $response;
    }

    /** @return array<string, mixed> */
    private function asArray(mixed $response): array
    {
        if (is_array($response)) {
            return $response;
        }

        if (is_object($response) && method_exists($response, 'asArray')) {
            return (array) $response->asArray();
        }

        if ($response instanceof \ArrayAccess) {
            return ['count' => $response['count'] ?? 0, 'deleted' => $response['deleted'] ?? 0];
        }

        return [];
    }

    /**
     * A 404 from either SDK, without naming an exception class that may not exist.
     *
     * Both carry the status on the exception code; Elasticsearch 8 also spells it
     * out in the message. Matching on the message alone would be fragile, and
     * matching on nothing would turn an unreachable cluster into a clean pass.
     */
    private function isNotFound(\Throwable $e): bool
    {
        if ((int) $e->getCode() === 404) {
            return true;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'index_not_found_exception')
            || str_contains($message, '404 not found')
            || str_contains($message, 'no such index');
    }
}
