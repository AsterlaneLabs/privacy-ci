<?php

declare(strict_types=1);

namespace PrivacyCI\Verification\Laravel;

use PrivacyCI\Lifecycle\DeletionRequest;
use PrivacyCI\Lifecycle\ErasureAuditor;
use PrivacyCI\Manifest\Manifest;
use PrivacyCI\Manifest\Subject;
use PrivacyCI\Verification\Footprint;
use PrivacyCI\Verification\FootprintResolver;
use PrivacyCI\Verification\Verifier;

/**
 * Snapshot verification, wired to the erasure lifecycle.
 *
 * Resolves the subject's footprint immediately before deletion and checks it
 * immediately after, attaching both to the request so one row carries the whole
 * story: what was there, what ran and what remained.
 */
final class SnapshotAuditor implements ErasureAuditor
{
    public function __construct(
        private readonly Manifest $manifest,
        private readonly Subject $subject,
        private readonly Verifier $verifier,
        private readonly FootprintResolver $resolver = new FootprintResolver,
    ) {
    }

    public function beforeDelete(DeletionRequest $request): DeletionRequest
    {
        $footprint = $this->resolver->resolve(
            $this->manifest,
            $this->subject,
            $request->subjectId,
            gmdate('Y-m-d\TH:i:s\Z'),
        );

        return $request->withFootprint($footprint->toJson());
    }

    public function afterDelete(DeletionRequest $request): DeletionRequest
    {
        if ($request->footprintJson === null) {
            return $request;
        }

        $result = $this->verifier->verify(
            Footprint::fromJson($request->footprintJson),
            gmdate('Y-m-d\TH:i:s\Z'),
        );

        return $request->withVerification((string) json_encode(
            $result->toArray() + ['fingerprint' => $result->fingerprint()],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));
    }
}
