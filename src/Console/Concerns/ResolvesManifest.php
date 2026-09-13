<?php

declare(strict_types=1);

namespace PrivacyCI\Console\Concerns;

use PrivacyCI\Discovery\Discoverer;
use PrivacyCI\Discovery\Models\ModelMap;
use PrivacyCI\Discovery\Scanners\ModelScanner;
use PrivacyCI\Manifest\Manifest;
use PrivacyCI\Manifest\Subject;
use PrivacyCI\Policy\PolicyCompiler;
use PrivacyCI\Policy\PrivacyPolicy;

/**
 * Turns config into a policy-applied manifest.
 *
 * Shared by discover, check and baseline so all three see exactly the same
 * findings, a check that disagreed with the report that produced its baseline
 * would be worse than no check at all.
 */
trait ResolvesManifest
{
    private ?ModelMap $scannedModels = null;

    protected function resolveManifest(Discoverer $discoverer, ?string $subjectType = null): ?Manifest
    {
        $subject = $this->resolveSubject($subjectType);

        if ($subject === null) {
            $this->components->error(
                'No subjects configured. Set privacy.subjects in config/privacy.php.',
            );

            return null;
        }

        $migrationPaths = $this->existingPaths('privacy.discovery.migration_paths');

        if ($migrationPaths === []) {
            $this->components->error(
                'No migration directories found. Check privacy.discovery.migration_paths.',
            );

            return null;
        }

        $modelPaths = $this->existingPaths('privacy.discovery.model_paths');
        $models = $this->scanModels($modelPaths);
        $lock = config('privacy.discovery.composer_lock');

        $manifest = $discoverer->discover(
            project: (string) config('app.name', 'application'),
            migrationPaths: $migrationPaths,
            configPaths: $this->existingPaths('privacy.discovery.config_paths'),
            composerLock: is_string($lock) && is_file($lock) ? $lock : null,
            subject: $subject,
            environment: (string) config('app.env', 'local'),
            modelPaths: $modelPaths,
            sourcePaths: $this->existingPaths('privacy.discovery.source_paths'),
            models: $models,
            onIssue: fn (string $message) => $this->components->warn($message),
        );

        return $this->applyPolicies($manifest, $modelPaths);
    }

    /**
     * Scans models once and says so when it finds none.
     *
     * A declared `belongsTo` is deterministic evidence, it links a table to the
     * subject even where the database has no constraint. Scanning zero models
     * silently disables that entire signal, and the finding that goes missing
     * looks exactly like a table that genuinely has no link.
     *
     * @param  list<string>  $modelPaths
     */
    protected function scanModels(array $modelPaths): ModelMap
    {
        if ($this->scannedModels !== null) {
            return $this->scannedModels;
        }

        $configured = (array) config('privacy.discovery.model_paths', []);

        if ($configured === []) {
            $this->components->warn(
                'privacy.discovery.model_paths is empty, so relationships are not being read. '
                .'Tables linked only by a declared belongsTo will look unlinked.',
            );

            return $this->scannedModels = new ModelMap;
        }

        if ($modelPaths === []) {
            $this->components->warn(sprintf(
                'None of the configured model paths exist (%s), so relationships are not '
                .'being read. Point privacy.discovery.model_paths at the directory your '
                .'models live in, then run config:clear.',
                implode(', ', array_map(strval(...), $configured)),
            ));

            return $this->scannedModels = new ModelMap;
        }

        $models = (new ModelScanner)
            ->withBaseClasses(array_map(
                strval(...),
                (array) config('privacy.discovery.model_base_classes', []),
            ))
            ->scan($modelPaths);

        if (count($models->all()) === 0) {
            $this->components->warn(sprintf(
                'No Eloquent models found under %s, so relationships are not being read. '
                .'If your models live elsewhere, set privacy.discovery.model_paths.',
                implode(', ', $modelPaths),
            ));
        }

        return $this->scannedModels = $models;
    }

    /** @return list<string> */
    protected function existingPaths(string $key): array
    {
        return array_values(array_filter((array) config($key, []), 'is_dir'));
    }

    /** @param list<string> $modelPaths */
    protected function applyPolicies(Manifest $manifest, array $modelPaths): Manifest
    {
        $policies = [];

        foreach ((array) config('privacy.policies', []) as $class) {
            if (is_string($class) && is_subclass_of($class, PrivacyPolicy::class)) {
                $policies[] = $this->laravel->make($class);
            }
        }

        if ($policies === []) {
            return $manifest;
        }

        return (new PolicyCompiler($this->scanModels($modelPaths)))->apply($manifest, ...$policies);
    }

    protected function resolveSubject(?string $requested): ?Subject
    {
        /** @var array<string, string> $subjects */
        $subjects = (array) config('privacy.subjects', []);

        if ($subjects === []) {
            return null;
        }

        if (is_string($requested) && $requested !== '') {
            return isset($subjects[$requested])
                ? new Subject($requested, $subjects[$requested])
                : null;
        }

        $type = (string) array_key_first($subjects);

        return new Subject($type, $subjects[$type]);
    }
}
