<?php

declare(strict_types=1);

namespace PrivacyCI\Policy;

use PrivacyCI\Discovery\Models\ModelMap;
use PrivacyCI\Manifest\Classification;
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

            if ($rule === null) {
                $classified[] = $this->keyOfAMaskedSubject($location, $rules) ?? $location;

                continue;
            }

            $classified[] = $rule->kind === LocationKind::DatabaseColumn
                ? $location->withClassification(
                    $rule->classification,
                    $rule->declaredAt,
                    $rule->reasonText(),
                    $this->replacementsFor($location, $rule),
                )
                : $this->claim($location, $rule);
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
            return $this->storeRuleFor($location, $rules);
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
     * The subject's key, on a subject that is masked rather than deleted.
     *
     * Masking names the columns to scrub, and the key is never one of them: it
     * identifies the row that has to survive. Left alone it stays unclassified
     * and fails CI forever, which would make every masked subject need a
     * retain() rule for a column nobody was ever going to touch.
     *
     * @param  list<Rule>  $rules
     */
    private function keyOfAMaskedSubject(Location $location, array $rules): ?Location
    {
        if ($location->linkage !== Linkage::SubjectRoot) {
            return null;
        }

        [$table] = $this->splitPath($location->path);

        foreach ($rules as $rule) {
            if ($rule->kind !== LocationKind::DatabaseColumn
                || $rule->classification !== Classification::Anonymize
                || $this->models->resolveTable($rule->target) !== $table) {
                continue;
            }

            return $location->withClassification(
                Classification::Retain,
                $rule->declaredAt,
                'subject key, kept so the masked row stays addressable',
            );
        }

        return null;
    }

    /**
     * The declared post-state for this column, if the rule names one.
     *
     * @return array<string, mixed>
     */
    private function replacementsFor(Location $location, Rule $rule): array
    {
        if ($rule->replacements === []) {
            return [];
        }

        $column = $this->splitPath($location->path)[1];

        return array_key_exists($column, $rule->replacements)
            ? [$column => $rule->replacements[$column]]
            : [];
    }

    /**
     * Re-home an inferred location onto the store its policy names.
     *
     * Static analysis guesses the connection or disk from the one call site it
     * saw. A policy states it. Keeping the guess would have a generated handler
     * delete from the wrong disk, which fails at the worst possible moment.
     */
    private function claim(Location $location, Rule $rule): Location
    {
        // A rule that never named a connection has not overridden anything. The
        // search scanner reads the cluster off the installed driver, and letting
        // an unfilled argument flatten that to 'default' both lost the answer and
        // wrote "store named by policy: default" about a policy that said no such
        // thing.
        $store = $rule->storeStated ? $rule->store : $location->store;

        return new Location(
            id: Location::idFor($rule->kind, $store, $location->path),
            kind: $rule->kind,
            store: $store,
            path: $location->path,
            subject: $location->subject,
            linkage: $location->linkage,
            confidence: $location->confidence,
            classification: $rule->classification,
            policySource: $rule->declaredAt,
            evidence: $rule->storeStated
                ? [...$location->evidence, 'store named by policy: '.$store]
                : $location->evidence,
            reason: $rule->reasonText(),
        );
    }

    /**
     * A declaration claims a key the scanner may already have inferred.
     *
     * Static analysis guesses the store from the call it saw, so the same Redis
     * key arrives as `cache` from Cache::put() and as `default` from the policy
     * that declares it. The developer's declaration is the authoritative one, so
     * it classifies the discovered location instead of sitting beside it.
     *
     * @param  list<Rule>  $rules
     */
    private function storeRuleFor(Location $location, array $rules): ?Rule
    {
        foreach ($rules as $rule) {
            if ($rule->kind === $location->kind && $rule->target === $location->path) {
                return $rule;
            }
        }

        return null;
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
            // Also key by kind and path, so a rule does not add a duplicate of a
            // location the scanner already found under a different store name.
            $existing[$location->kind->value.':'.$location->path] = true;
        }

        $added = [];

        foreach ($rules as $rule) {
            if ($rule->kind === LocationKind::DatabaseColumn) {
                continue;
            }

            $id = Location::idFor($rule->kind, $rule->store, $rule->target);

            if (isset($existing[$id]) || isset($existing[$rule->kind->value.':'.$rule->target])) {
                continue;
            }

            $existing[$id] = true;
            $existing[$rule->kind->value.':'.$rule->target] = true;

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
