<?php

declare(strict_types=1);

namespace PrivacyCI\Lifecycle;

interface DeletionStore
{
    public function save(DeletionRequest $request): void;

    public function find(string $id): ?DeletionRequest;

    /** The open request for a subject, if one exists. */
    public function pendingFor(string $subjectType, string $subjectId): ?DeletionRequest;

    /**
     * Requests whose grace period has elapsed.
     *
     * @return list<DeletionRequest>
     */
    public function due(\DateTimeImmutable $now, int $limit = 100): array;

    /**
     * Open requests whose window closes on or before $threshold.
     *
     * Lets the reminder pass ask the database for the few requests approaching
     * their deadline rather than loading every open request and filtering in PHP.
     *
     * @return list<DeletionRequest>
     */
    public function pendingWithin(\DateTimeImmutable $threshold, int $limit = 100): array;

    /** @return list<DeletionRequest> */
    public function all(): array;
}
