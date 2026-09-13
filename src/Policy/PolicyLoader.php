<?php

declare(strict_types=1);

namespace PrivacyCI\Policy;

/**
 * Finds policy classes on disk without needing the host application's autoloader.
 *
 * The standalone binary has no Laravel container to resolve App\Privacy\* from, so
 * policy files are required directly and the newly declared subclasses picked up.
 */
final class PolicyLoader
{
    /**
     * @param  list<string>  $paths
     * @return list<PrivacyPolicy>
     */
    public function load(array $paths): array
    {
        $policies = [];

        foreach ($paths as $path) {
            foreach ($this->candidates($path) as $file) {
                $before = get_declared_classes();

                try {
                    require_once $file;
                } catch (\Throwable) {
                    continue;
                }

                foreach (array_diff(get_declared_classes(), $before) as $class) {
                    $policy = $this->instantiate($class);

                    if ($policy !== null) {
                        $policies[] = $policy;
                    }
                }
            }
        }

        return $policies;
    }

    /** @return list<string> */
    private function candidates(string $path): array
    {
        if (is_file($path)) {
            return [$path];
        }

        if (! is_dir($path)) {
            return [];
        }

        $files = array_values(array_filter((array) glob(rtrim($path, '/').'/*.php'), 'is_string'));
        sort($files);

        return $files;
    }

    private function instantiate(string $class): ?PrivacyPolicy
    {
        if (! is_subclass_of($class, PrivacyPolicy::class)) {
            return null;
        }

        $reflection = new \ReflectionClass($class);

        if ($reflection->isAbstract() || $reflection->isAnonymous()) {
            return null;
        }

        if ($reflection->getConstructor()?->getNumberOfRequiredParameters() > 0) {
            return null;
        }

        /** @var PrivacyPolicy */
        return $reflection->newInstance();
    }
}
