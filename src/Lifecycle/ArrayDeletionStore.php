<?php

declare(strict_types=1);

namespace PrivacyCI\Lifecycle;

/** In-memory store, for tests and for dry runs. */
final class ArrayDeletionStore implements DeletionStore
{
    /** @var array<string, DeletionRequest> */
    private array $requests = [];

    public function save(DeletionRequest $request): void
    {
        $this->requests[$request->id] = $request;
    }

    public function find(string $id): ?DeletionRequest
    {
        return $this->requests[$id] ?? null;
    }

    public function pendingFor(string $subjectType, string $subjectId): ?DeletionRequest
    {
        foreach ($this->requests as $request) {
            if ($request->subjectType === $subjectType
                && $request->subjectId === $subjectId
                && $request->status === DeletionStatus::PendingDelete) {
                return $request;
            }
        }

        return null;
    }

    public function due(\DateTimeImmutable $now, int $limit = 100): array
    {
        $due = array_values(array_filter(
            $this->requests,
            static fn (DeletionRequest $r): bool => $r->isDue($now),
        ));

        usort(
            $due,
            static fn (DeletionRequest $a, DeletionRequest $b): int => $a->executeAfter <=> $b->executeAfter,
        );

        return array_slice($due, 0, $limit);
    }

    public function pendingWithin(\DateTimeImmutable $threshold, int $limit = 100): array
    {
        $matching = array_values(array_filter(
            $this->requests,
            static fn (DeletionRequest $r): bool => $r->status === DeletionStatus::PendingDelete
                && $r->executeAfter <= $threshold,
        ));

        usort(
            $matching,
            static fn (DeletionRequest $a, DeletionRequest $b): int => $a->executeAfter <=> $b->executeAfter,
        );

        return array_slice($matching, 0, $limit);
    }

    public function all(): array
    {
        return array_values($this->requests);
    }
}
