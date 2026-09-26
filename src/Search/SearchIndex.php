<?php

declare(strict_types=1);

namespace PrivacyCI\Search;

/**
 * The four questions privacy work asks of a search index.
 *
 * Deliberately not the SDK's interface. Elasticsearch and OpenSearch return
 * different things from the same call, generated handlers must not be pinned to
 * whichever one happened to be installed when the policy was written, and
 * erasure and verification have to agree on what "gone" means or the report is
 * worthless.
 *
 * Documents are addressed two ways, and only one of them works per index:
 * `exists`/`deleteDocument` when the subject *is* the document, `count`/
 * `deleteByQuery` when they are a field on documents keyed by something else.
 */
interface SearchIndex
{
    /** @throws \Throwable When the cluster cannot be reached. Never answer "no" for "could not ask". */
    public function exists(string $index, string $id, string $connection = 'default'): bool;

    /** @return int Documents still matching. */
    public function count(string $index, string $field, string $value, string $connection = 'default'): int;

    /** Deleting an id that is not there is a success, not an error. */
    public function deleteDocument(string $index, string $id, string $connection = 'default'): void;

    /** @return int Documents deleted. */
    public function deleteByQuery(string $index, string $field, string $value, string $connection = 'default'): int;
}
