<?php

declare(strict_types=1);

namespace PrivacyCI\Verification\Probes;

use PrivacyCI\Manifest\LocationKind;
use PrivacyCI\Search\SearchIndex;
use PrivacyCI\Verification\Address;
use PrivacyCI\Verification\Probe;

/**
 * Asks a search index whether the subject is still in it.
 *
 * This is the probe that most often catches a real failure. An erasure written
 * against the database alone leaves the index untouched, and the index is the
 * copy with a public search box in front of it.
 *
 * Two questions, picked by how the address is built: an exact document when the
 * subject is the document, a count when they are a field on documents keyed by
 * something else. Never a "does the index exist" check, which passes forever.
 */
final class SearchProbe implements Probe
{
    public function __construct(private readonly SearchIndex $search)
    {
    }

    public function handles(Address $address): bool
    {
        return $address->kind === LocationKind::SearchIndex;
    }

    public function exists(Address $address): bool
    {
        $index = $address->locator['index'] ?? null;

        if ($index === null) {
            throw new \RuntimeException('no index in locator');
        }

        $id = $address->locator['id'] ?? null;

        if ($id !== null) {
            return $this->search->exists($index, $id, $address->store);
        }

        $field = $address->locator['field'] ?? null;
        $value = $address->locator['value'] ?? null;

        if ($field === null || $value === null) {
            // The resolver marks these Unverifiable, so reaching here means the
            // footprint was hand-edited. Throwing keeps it UNCHECKED rather than
            // letting a malformed address report a clean pass.
            throw new \RuntimeException('no document id and no field to query in locator');
        }

        return $this->search->count($index, $field, $value, $address->store) > 0;
    }
}
