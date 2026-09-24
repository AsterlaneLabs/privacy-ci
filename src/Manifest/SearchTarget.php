<?php

declare(strict_types=1);

namespace PrivacyCI\Manifest;

/**
 * One addressable thing inside a search index.
 *
 * A search index is document-addressed, not key-addressed, and there are two
 * ways a person appears in one:
 *
 *     users/{id}          the subject *is* the document; its id is the doc id
 *     posts?user_id={id}  the subject is a *field* on documents keyed by something else
 *
 * The distinction is not cosmetic. A post's document id is the post's id, so
 * nothing can delete or check it by the subject's id alone, only a query on the
 * field can. Collapsing the two into one flat "key" is what made the first
 * version of this generate `Redis::del("users_index")`.
 *
 * A bare index name parses to neither form on purpose. `users` names a place,
 * not a person inside it, and reporting that it "exists" would be true of every
 * index forever.
 */
final readonly class SearchTarget
{
    public function __construct(
        public string $index,
        public ?string $documentId = null,
        public ?string $field = null,
        public ?string $value = null,
    ) {
    }

    public static function parse(string $path): self
    {
        $query = strpos($path, '?');

        if ($query !== false) {
            $index = substr($path, 0, $query);
            $clause = substr($path, $query + 1);
            $equals = strpos($clause, '=');

            // `posts?user_id` names a field with nothing to match it against.
            if ($equals === false) {
                return new self($index);
            }

            return new self(
                index: $index,
                field: substr($clause, 0, $equals),
                value: substr($clause, $equals + 1),
            );
        }

        $slash = strpos($path, '/');

        if ($slash === false) {
            return new self($path);
        }

        return new self(
            index: substr($path, 0, $slash),
            documentId: substr($path, $slash + 1),
        );
    }

    /** The subject is the document, addressable by id. */
    public function isDocument(): bool
    {
        return $this->documentId !== null && $this->documentId !== '';
    }

    /** The subject is a field on documents keyed by something else. */
    public function isQuery(): bool
    {
        return $this->field !== null && $this->field !== ''
            && $this->value !== null && $this->value !== '';
    }

    /** Neither form: an index named with no way to find a person in it. */
    public function isWholeIndex(): bool
    {
        return ! $this->isDocument() && ! $this->isQuery();
    }

    /** Round-trips through parse(), so a target can be stored as a Location path. */
    public function path(): string
    {
        return match (true) {
            $this->isQuery() => "{$this->index}?{$this->field}={$this->value}",
            $this->isDocument() => "{$this->index}/{$this->documentId}",
            default => $this->index,
        };
    }

    /** How a report should read this, once resolved against a subject. */
    public function describe(): string
    {
        return match (true) {
            $this->isQuery() => sprintf('%s where %s = %s', $this->index, $this->field, $this->value),
            $this->isDocument() => "{$this->index}/{$this->documentId}",
            default => $this->index,
        };
    }

    /** The same target with its placeholders resolved to a concrete subject. */
    public function withValues(callable $resolve): self
    {
        return new self(
            index: $this->index,
            documentId: $this->documentId === null ? null : $resolve($this->documentId),
            field: $this->field,
            value: $this->value === null ? null : $resolve($this->value),
        );
    }
}
