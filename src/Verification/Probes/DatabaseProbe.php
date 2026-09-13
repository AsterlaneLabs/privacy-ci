<?php

declare(strict_types=1);

namespace PrivacyCI\Verification\Probes;

use Illuminate\Database\DatabaseManager;
use PrivacyCI\Manifest\LocationKind;
use PrivacyCI\Verification\Address;
use PrivacyCI\Verification\Probe;

final class DatabaseProbe implements Probe
{
    public function __construct(private readonly DatabaseManager $db)
    {
    }

    public function handles(Address $address): bool
    {
        return $address->kind === LocationKind::DatabaseColumn;
    }

    public function exists(Address $address): bool
    {
        $table = $address->locator['table'] ?? null;
        $column = $address->locator['column'] ?? null;
        $value = $address->locator['value'] ?? null;

        if ($table === null || $column === null || $value === null) {
            throw new \RuntimeException('incomplete locator for a database address');
        }

        $connection = $address->store === 'primary' ? null : $address->store;

        $query = $this->db->connection($connection)->table($table)->where($column, $value);

        // A masked row is meant to survive. What must not survive is a value in
        // any of the columns the policy said to empty.
        if (($masked = $address->locator['masked'] ?? null) !== null) {
            $columns = array_filter(explode(',', $masked));

            $query->where(static function ($q) use ($columns): void {
                foreach ($columns as $c) {
                    $q->orWhereNotNull($c);
                }
            });
        }

        // A missing table would throw, which the verifier records as unchecked.
        // That is the right outcome: we did not establish absence, we failed to look.
        return $query->exists();
    }
}
