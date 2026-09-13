<?php

declare(strict_types=1);

namespace PrivacyCI\Policy;

use PrivacyCI\Manifest\Classification;
use PrivacyCI\Manifest\LocationKind;

/**
 * Policy as code.
 *
 * Rules live in the application repository, so they are versioned, reviewed in
 * pull requests, and deployed with the application. Changing a table from RETAIN
 * to DELETE becomes a code review with a named approver, which is the thing
 * auditors actually want to see.
 */
abstract class PrivacyPolicy
{
    /** @var list<Rule> */
    private array $rules = [];

    private ?string $subjectType = null;

    private ?string $subjectRoot = null;

    abstract public function configure(): void;

    /** @return list<Rule> */
    public function rules(): array
    {
        if ($this->rules === []) {
            $this->configure();
            $this->validate();
        }

        return $this->rules;
    }

    public function subjectType(): ?string
    {
        $this->rules();

        return $this->subjectType;
    }

    public function subjectRoot(): ?string
    {
        $this->rules();

        return $this->subjectRoot;
    }

    /** Declare whose data this policy governs. */
    protected function subject(string $target, string $root = 'id', string $type = 'user'): void
    {
        $this->subjectType = $type;
        $this->subjectRoot = $root;
    }

    /** @param list<string>|null $columns */
    protected function delete(string $target, ?array $columns = null): Rule
    {
        return $this->push($target, Classification::Delete, LocationKind::DatabaseColumn, $columns);
    }

    /**
     * @param  array<string, mixed>|list<string>|null  $columns  Either a list of
     *         column names, or a column => replacement map as the plan's example writes it.
     */
    protected function anonymize(string $target, array|null $columns = null): Rule
    {
        return $this->push(
            $target,
            Classification::Anonymize,
            LocationKind::DatabaseColumn,
            $columns === null ? null : $this->columnNames($columns),
            replacements: $columns === null || array_is_list($columns) ? [] : $columns,
        );
    }

    /** @param list<string>|null $columns */
    protected function retain(string $target, ?array $columns = null): Rule
    {
        return $this->push($target, Classification::Retain, LocationKind::DatabaseColumn, $columns);
    }

    /**
     * Suppress a finding. The reason is mandatory, this is the escape hatch,
     * and an unexplained one is indistinguishable from an oversight.
     *
     *     $this->ignore(FeatureFlag::class)->reason('internal flag, no subject link');
     *
     * @param  list<string>|null  $columns
     */
    protected function ignore(string $target, ?array $columns = null): Rule
    {
        return $this->push($target, Classification::Ignore, LocationKind::DatabaseColumn, $columns);
    }

    /** @param list<string>|null $columns */
    protected function custom(string $target, string $handler, ?array $columns = null): Rule
    {
        return $this->push($target, Classification::Custom, LocationKind::DatabaseColumn, $columns)
            ->using($handler);
    }

    protected function deleteRedis(string $pattern, string $connection = 'default'): Rule
    {
        return $this->push($pattern, Classification::Delete, LocationKind::RedisKey, null, $connection);
    }

    protected function deleteStorage(string $path, string $disk = 'local'): Rule
    {
        return $this->push($path, Classification::Delete, LocationKind::ObjectStorage, null, $disk);
    }

    protected function deleteSearch(string $index, string $connection = 'default'): Rule
    {
        return $this->push($index, Classification::Delete, LocationKind::SearchIndex, null, $connection);
    }

    protected function deleteService(string $service, string $handler): Rule
    {
        return $this->push($service, Classification::Custom, LocationKind::ExternalService, null, $service)
            ->using($handler);
    }

    /** @param list<string>|null $columns */
    /**
     * @param  list<string>|null     $columns
     * @param  array<string, mixed>  $replacements
     */
    private function push(
        string $target,
        Classification $classification,
        LocationKind $kind,
        ?array $columns,
        string $store = 'primary',
        array $replacements = [],
    ): Rule {
        $rule = new Rule(
            target: $target,
            classification: $classification,
            kind: $kind,
            columns: $columns,
            store: $store,
            declaredAt: $this->callerLocation(),
            replacements: $replacements,
        );

        $this->rules[] = $rule;

        return $rule;
    }

    /**
     * Where in the policy file this rule was written, so a report can point a
     * developer straight at the line rather than at the class.
     */
    private function callerLocation(): ?string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6) as $frame) {
            $file = $frame['file'] ?? null;

            if (is_string($file) && $file !== __FILE__) {
                return basename($file).':'.($frame['line'] ?? 0);
            }
        }

        return null;
    }

    /** @param array<string, mixed>|list<string> $columns @return list<string> */
    private function columnNames(array $columns): array
    {
        return array_is_list($columns)
            ? array_values(array_map(strval(...), $columns))
            : array_keys($columns);
    }

    private function validate(): void
    {
        foreach ($this->rules as $rule) {
            if ($rule->classification->requiresReason() && ($rule->reasonText() ?? '') === '') {
                throw new InvalidPolicy(sprintf(
                    '%s on "%s" needs a documented reason: ->reason("...")%s',
                    $rule->classification->value,
                    $rule->target,
                    $rule->declaredAt !== null ? " (declared at {$rule->declaredAt})" : '',
                ));
            }

            // An empty string is not a handler; treating it as one would let a
            // typo silently disable deletion for that location.
            if ($rule->classification === Classification::Custom && ($rule->handler() ?? '') === '') {
                throw new InvalidPolicy(sprintf(
                    'CUSTOM on "%s" needs a handler class%s.',
                    $rule->target,
                    $rule->declaredAt !== null ? " (declared at {$rule->declaredAt})" : '',
                ));
            }
        }
    }
}
