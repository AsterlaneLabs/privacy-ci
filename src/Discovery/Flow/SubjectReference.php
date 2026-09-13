<?php

declare(strict_types=1);

namespace PrivacyCI\Discovery\Flow;

use PrivacyCI\Manifest\Subject;

/**
 * Decides whether an interpolated expression names the data subject.
 *
 * This is the whole precision problem in one place. `"user:{$userId}"` almost
 * certainly keys on a person; `"report:{$reportId}"` almost certainly does not.
 * We can only judge by naming, which is exactly why everything this produces is
 * Linkage::Inferred and may never fail a build.
 */
final class SubjectReference
{
    public function __construct(private readonly Subject $subject)
    {
    }

    /**
     * @param  list<string>  $refs  Rendered expressions, e.g. ["userId", "user.id"].
     * @return array{confidence: float, matched: string}|null
     */
    public function match(array $refs): ?array
    {
        $type = strtolower($this->subject->type);
        $table = strtolower($this->subject->rootTable());
        $singular = rtrim($table, 's');

        $best = null;

        foreach ($refs as $ref) {
            $normalised = strtolower(preg_replace('/[^a-z0-9]/i', '', $ref) ?? $ref);
            $confidence = null;

            // $user->id, $user->getKey(), names the subject and takes its key.
            if (preg_match('/^(\\$?)('.preg_quote($type, '/').'|'.preg_quote($singular, '/').')(id|getkey|key|uuid|identifier)$/', $normalised) === 1) {
                $confidence = 0.8;
            } elseif (preg_match('/^('.preg_quote($type, '/').'|'.preg_quote($singular, '/').')id$/', $normalised) === 1) {
                // $userId
                $confidence = 0.8;
            } elseif (str_contains($normalised, $type) || str_contains($normalised, $singular)) {
                // $currentUser, $userProfile->id, mentions the subject but the
                // shape is less certain.
                $confidence = 0.6;
            } elseif (preg_match('/^(id|uuid|identifier)$/', $normalised) === 1) {
                // A bare $id inside a key. Could be anything; flag weakly rather
                // than silently dropping it.
                $confidence = 0.35;
            }

            if ($confidence !== null && ($best === null || $confidence > $best['confidence'])) {
                $best = ['confidence' => $confidence, 'matched' => $ref];
            }
        }

        return $best;
    }
}
