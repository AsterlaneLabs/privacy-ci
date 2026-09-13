<?php

declare(strict_types=1);

namespace PrivacyCI\Manifest;

/**
 * Why we believe a location holds personal data.
 *
 * The distinction is load-bearing: only deterministic linkages may fail CI.
 * Anything inferred can warn, never block a build.
 */
enum Linkage: string
{
    /** The subject's own root table, e.g. users.id. */
    case SubjectRoot = 'subject_root';

    /** A declared foreign key reaching the subject root. Deterministic. */
    case ForeignKey = 'foreign_key';

    /** An Eloquent relationship declaring the association. Deterministic. */
    case Relationship = 'relationship';

    /** Column name matched a personal-data pattern. Probabilistic. */
    case Heuristic = 'heuristic';

    /** Named explicitly in a privacy policy file. Deterministic. */
    case Declared = 'declared';

    /** Static analysis of framework calls suggested it. Probabilistic. */
    case Inferred = 'inferred';

    /**
     * Deterministic linkages may fail a build. Probabilistic ones may only warn:
     * a false positive that blocks a deploy costs us the customer permanently.
     */
    public function isDeterministic(): bool
    {
        return match ($this) {
            self::SubjectRoot, self::ForeignKey, self::Relationship, self::Declared => true,
            self::Heuristic, self::Inferred => false,
        };
    }
}
