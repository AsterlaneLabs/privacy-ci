<?php

declare(strict_types=1);

namespace PrivacyCI\Policy;

/**
 * A stable hash of the rules in force.
 *
 * Recorded against every erasure so the audit trail can answer "under which
 * rules was this person deleted", which is the first question anyone asks when
 * a policy has changed since.
 *
 * Hashes the compiled rules rather than the discovered schema: instantiating
 * policies is cheap, and running full discovery when somebody clicks "delete my
 * account" would not be.
 */
final class PolicyFingerprint
{
    public static function of(PrivacyPolicy ...$policies): ?string
    {
        $rules = [];

        foreach ($policies as $policy) {
            foreach ($policy->rules() as $rule) {
                $rules[] = [
                    'target' => $rule->target,
                    'classification' => $rule->classification->value,
                    'kind' => $rule->kind->value,
                    'store' => $rule->store,
                    'columns' => $rule->columns,
                    'replacements' => array_keys($rule->replacements),
                    'handler' => $rule->handler(),
                    'reason' => $rule->reasonText(),
                ];
            }
        }

        if ($rules === []) {
            return null;
        }

        // Sorted so the hash describes the rules, not the order they were written.
        usort($rules, static fn (array $a, array $b): int => [$a['target'], $a['kind'], $a['store']]
            <=> [$b['target'], $b['kind'], $b['store']]);

        return 'sha256:'.hash('sha256', json_encode($rules, JSON_THROW_ON_ERROR));
    }
}
