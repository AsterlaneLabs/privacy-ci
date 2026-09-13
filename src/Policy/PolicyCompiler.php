<?php

declare(strict_types=1);

namespace PrivacyCI\Policy;

use PrivacyCI\Discovery\Models\ModelMap;
use PrivacyCI\Manifest\Linkage;
use PrivacyCI\Manifest\Location;
use PrivacyCI\Manifest\LocationKind;
use PrivacyCI\Manifest\Manifest;

/**
 * Applies a policy's rules to a discovered manifest.
 *
 * Database rules classify locations discovery already found. Store rules
 * (Redis, object storage, search, third-party services) *add* locations, because
 * the developer is telling us about somewhere the scanner cannot reach on its
 * own, and a declared location belongs on the map just as much as a found one.
 */
final class PolicyCompiler
{
    public function __construct(private readonly ModelMap $models = new ModelMap)
    {
    }

    public function apply(Manifest $manifest, PrivacyPolicy ...$policies): Manifest
    {
        /** @var list<Rule> $rules */
        $rules = [];

        foreach ($policies as $policy) {
            foreach ($policy->rules() as $rule) {
                $rules[] = $rule;
            }
        }

        $classified = [];

        foreach ($manifest->locations() as $location) {
            $rule = $this->bestRuleFor($location, $rules);

            $classified[] = $rule === null
                ? $location
                : $location->withClassification(
                    $rule->classification,
                    $rule->declaredAt,
                    $rule->reasonText(),
                );
        }

        foreach ($this->declaredLocations($rules, $manifest) as $location) {
            $classified[] = $location;
        }

        return $manifest->withLocations(...$classified);
    }

    /**
     * The most specific rule wins; among equally specific rules, the last one
     * declared wins, so a broad rule can be written first and then narrowed.
     *
     * @param  list<Rule>  $rules
     */
    private function bestRuleFor(Location $location, array $rules): ?Rule
    {
        if ($location->kind !== LocationKind::DatabaseColumn) {
            return null;
        }

        [$table, $column] = $this->splitPath($location->path);
        $best = null;

        foreach ($rules as $rule) {
            if ($rule->kind !== LocationKind::DatabaseColumn) {
                continue;
            }

            if ($this->models->resolveTable($rule->target) !== $table) {
                continue;
            }

            if (! $rule->coversColumn($column)) {
                continue;
            }

            if ($best === null
                || $rule->isColumnSpecific()
                || ! $best->isColumnSpecific()) {
                $best = $rule;
            }
        }

        return $best;
    }

    /**
     * @param  list<Rule>  $rules
     * @return list<Location>
     */
    private function declaredLocations(array $rules, Manifest $manifest): array
    {
        $subject = $manifest->subjects[0]->type ?? 'user';
        $existing = [];

        foreach ($manifest->locations() as $location) {
            $existing[$location->id] = true;
        }

        $added = [];

        foreach ($rules as $rule) {
            if ($rule->kind === LocationKind::DatabaseColumn) {
                continue;
            }

            $id = Location::idFor($rule->kind, $rule->store, $rule->target);

            if (isset($existing[$id])) {
                continue;
            }

            $existing[$id] = true;

            $evidence = ['declared in privacy policy'];

            if ($rule->handler() !== null) {
                $evidence[] = 'handled by '.$rule->handler();
            }

            $added[] = new Location(
                id: $id,
                kind: $rule->kind,
                store: $rule->store,
                path: $rule->target,
                subject: $subject,
                linkage: Linkage::Declared,
                confidence: 1.0,
                classification: $rule->classification,
                policySource: $rule->declaredAt,
                evidence: $evidence,
                reason: $rule->reasonText(),
            );
        }

        return $added;
    }

    /** @return array{0: string, 1: string} */
    private function splitPath(string $path): array
    {
        $pos = strrpos($path, '.');

        return $pos === false ? [$path, ''] : [substr($path, 0, $pos), substr($path, $pos + 1)];
    }
}
