<?php

declare(strict_types=1);

namespace PrivacyCI\Generation;

use PrivacyCI\Discovery\Models\ModelMap;
use PrivacyCI\Manifest\Classification;
use PrivacyCI\Manifest\Linkage;
use PrivacyCI\Manifest\Location;
use PrivacyCI\Manifest\LocationKind;
use PrivacyCI\Manifest\Manifest;
use PrivacyCI\Manifest\Subject;

/**
 * Turns a classified manifest into an ordered deletion plan.
 *
 * Ordering is the whole difficulty. Deleting the subject's row first would have
 * the database reject every dependent delete that follows, or, worse, succeed
 * and orphan them. So steps run furthest-from-the-subject first and the root
 * table goes last, which is also the order a human would write by hand.
 */
final class HandlerPlanner
{
    /**
     * @param  array<string, int>  $tableDepth  Table => hops from the subject root.
     */
    public function plan(
        Manifest $manifest,
        Subject $subject,
        array $tableDepth = [],
        ModelMap $models = new ModelMap,
    ): HandlerPlan {
        $rootTable = $subject->rootTable();

        /** @var array<string, list<Location>> $byTable */
        $byTable = [];
        $steps = [];
        $unresolved = [];

        foreach ($manifest->locations() as $location) {
            if (! $location->classification->isResolved()) {
                $unresolved[] = $location->path;

                continue;
            }

            if ($location->kind === LocationKind::DatabaseColumn) {
                [$table] = $this->split($location->path);
                $byTable[$table][] = $location;

                continue;
            }

            $step = $this->storeStep($location);

            if ($step !== null) {
                $steps[] = $step;
            }
        }

        foreach ($byTable as $table => $locations) {
            $step = $this->tableStep($table, $locations, $rootTable, $tableDepth, $models);

            if ($step !== null) {
                $steps[] = $step;
            }
        }

        // Deepest first; the subject's own row last. Ties broken alphabetically
        // so regenerating an unchanged policy produces an identical file.
        usort($steps, static function (HandlerStep $a, HandlerStep $b): int {
            return [$b->depth, $a->label] <=> [$a->depth, $b->label];
        });

        sort($unresolved);

        return new HandlerPlan($steps, array_values(array_unique($unresolved)));
    }

    /**
     * @param  list<Location>      $locations
     * @param  array<string, int>  $tableDepth
     */
    private function tableStep(
        string $table,
        array $locations,
        string $rootTable,
        array $tableDepth,
        ModelMap $models,
    ): ?HandlerStep {
        $classification = $this->dominant($locations);

        if ($classification === Classification::Ignore) {
            return null;
        }

        $model = $models->forTable($table);
        $isRoot = $table === $rootTable;
        $depth = $isRoot ? 0 : ($tableDepth[$table] ?? 1);

        $foreignKey = $isRoot ? null : $this->foreignKey($locations);
        $notes = [];
        $actionable = true;

        if (! $isRoot && $foreignKey === null) {
            // Personal data with no foreign key, subscribers.email and friends.
            // We know it is there and cannot write the where clause for you.
            $notes[] = 'no foreign key to the subject; supply the lookup yourself';
            $actionable = false;
        }

        $replacements = [];

        if ($classification === Classification::Anonymize) {
            $replacements = $this->replacements($locations, $foreignKey);

            if ($replacements === []) {
                $notes[] = 'policy named no replacement values';
                $actionable = false;
            }
        }

        if ($classification === Classification::Retain) {
            $reason = $this->reason($locations);
            $notes[] = $reason !== null ? "retained: {$reason}" : 'retained by policy';
            $actionable = false;
        }

        return new HandlerStep(
            label: $table,
            classification: $classification,
            depth: $depth,
            modelClass: $model?->class,
            table: $table,
            foreignKey: $foreignKey,
            replacements: $replacements,
            handler: $classification === Classification::Custom ? $this->handlerFor($locations) : null,
            notes: $notes,
            actionable: $actionable,
        );
    }

    private function storeStep(Location $location): ?HandlerStep
    {
        if ($location->classification === Classification::Ignore) {
            return null;
        }

        return new HandlerStep(
            label: $location->path,
            classification: $location->classification,
            // Stores are cleared before any row is touched: once the subject's
            // row is gone the key pattern can no longer be resolved.
            depth: PHP_INT_MAX,
            store: $location->store,
            pattern: $location->path,
            handler: $location->classification === Classification::Custom
                ? $this->handlerFromEvidence($location)
                : null,
            notes: $location->linkage === Linkage::Inferred
                ? ['inferred by static analysis, confirm this key before relying on it']
                : [],
            kind: $location->kind,
            actionable: $location->kind !== LocationKind::ExternalService
                || $location->classification === Classification::Custom,
        );
    }

    /** @param list<Location> $locations */
    private function dominant(array $locations): Classification
    {
        // A table with any DELETE is deleted; otherwise the strongest action wins.
        $order = [
            Classification::Delete->value => 5,
            Classification::Custom->value => 4,
            Classification::Anonymize->value => 3,
            Classification::Retain->value => 2,
            Classification::Ignore->value => 1,
        ];

        $best = Classification::Ignore;

        foreach ($locations as $location) {
            if ($order[$location->classification->value] > $order[$best->value]) {
                $best = $location->classification;
            }
        }

        return $best;
    }

    /** @param list<Location> $locations */
    private function foreignKey(array $locations): ?string
    {
        foreach ($locations as $location) {
            if (in_array($location->linkage, [Linkage::ForeignKey, Linkage::Relationship], true)) {
                return $this->split($location->path)[1];
            }
        }

        return null;
    }

    /**
     * @param  list<Location>  $locations
     * @return array<string, mixed>
     */
    private function replacements(array $locations, ?string $foreignKey): array
    {
        $out = [];

        foreach ($locations as $location) {
            if ($location->classification !== Classification::Anonymize) {
                continue;
            }

            $out[$this->split($location->path)[1]] = null;
        }

        // The foreign key is what links the row to the person; nulling it is the
        // point of anonymising rather than deleting.
        if ($foreignKey !== null) {
            $out[$foreignKey] = null;
        }

        ksort($out);

        return $out;
    }

    /** @param list<Location> $locations */
    private function reason(array $locations): ?string
    {
        foreach ($locations as $location) {
            if ($location->reason !== null) {
                return $location->reason;
            }
        }

        return null;
    }

    /** @param list<Location> $locations */
    private function handlerFor(array $locations): ?string
    {
        foreach ($locations as $location) {
            $handler = $this->handlerFromEvidence($location);

            if ($handler !== null) {
                return $handler;
            }
        }

        return null;
    }

    private function handlerFromEvidence(Location $location): ?string
    {
        foreach ($location->evidence as $line) {
            if (str_starts_with($line, 'handled by ')) {
                return substr($line, strlen('handled by '));
            }
        }

        return null;
    }

    /** @return array{0: string, 1: string} */
    private function split(string $path): array
    {
        $pos = strrpos($path, '.');

        return $pos === false ? [$path, ''] : [substr($path, 0, $pos), substr($path, $pos + 1)];
    }
}
