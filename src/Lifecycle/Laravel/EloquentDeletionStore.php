<?php

declare(strict_types=1);

namespace PrivacyCI\Lifecycle\Laravel;

use Illuminate\Database\ConnectionInterface;
use PrivacyCI\Lifecycle\DeletionRequest;
use PrivacyCI\Lifecycle\DeletionStatus;
use PrivacyCI\Lifecycle\DeletionStore;

/**
 * Query-builder backed store.
 *
 * Deliberately not an Eloquent model: shipping one would put a class in the
 * application's namespace for it to collide with, and this table is ours.
 */
final class EloquentDeletionStore implements DeletionStore
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $table = 'privacy_deletion_requests',
    ) {
    }

    public function save(DeletionRequest $request): void
    {
        $this->connection->table($this->table)->updateOrInsert(
            ['id' => $request->id],
            [
                'subject_type' => $request->subjectType,
                'subject_id' => $request->subjectId,
                'status' => $request->status->value,
                'requested_at' => $request->requestedAt->format('Y-m-d H:i:s'),
                'execute_after' => $request->executeAfter->format('Y-m-d H:i:s'),
                'requested_via' => $request->requestedVia,
                'resolved_at' => $request->resolvedAt?->format('Y-m-d H:i:s'),
                'resolved_reason' => $request->resolvedReason,
                'policy_fingerprint' => $request->policyFingerprint,
                'attempts' => $request->attempts,
                'suspended_at' => $request->suspendedAt?->format('Y-m-d H:i:s'),
                'reminders_sent' => json_encode($request->remindersSent),
                'footprint' => $request->footprintJson,
                'verification' => $request->verificationJson,
                'open_key' => $this->openKey($request),
            ],
        );
    }

    public function find(string $id): ?DeletionRequest
    {
        $row = $this->connection->table($this->table)->where('id', $id)->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function pendingFor(string $subjectType, string $subjectId): ?DeletionRequest
    {
        $row = $this->connection->table($this->table)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('status', DeletionStatus::PendingDelete->value)
            ->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function due(\DateTimeImmutable $now, int $limit = 100): array
    {
        $rows = $this->connection->table($this->table)
            ->where('status', DeletionStatus::PendingDelete->value)
            ->where('execute_after', '<=', $now->format('Y-m-d H:i:s'))
            ->orderBy('execute_after')
            ->limit($limit)
            ->get();

        return array_map($this->hydrate(...), iterator_to_array($rows));
    }

    public function pendingWithin(\DateTimeImmutable $threshold, int $limit = 100): array
    {
        $rows = $this->connection->table($this->table)
            ->where('status', DeletionStatus::PendingDelete->value)
            ->where('execute_after', '<=', $threshold->format('Y-m-d H:i:s'))
            ->orderBy('execute_after')
            ->limit($limit)
            ->get();

        return array_map($this->hydrate(...), iterator_to_array($rows));
    }

    public function all(): array
    {
        $rows = $this->connection->table($this->table)->orderBy('requested_at')->get();

        return array_map($this->hydrate(...), iterator_to_array($rows));
    }

    /**
     * Set only while a request is open, so the unique index enforces "one open
     * request per subject" without also forbidding repeated cancelled ones.
     */
    private function openKey(DeletionRequest $request): ?string
    {
        return $request->status === DeletionStatus::PendingDelete
            ? $request->subjectType.':'.$request->subjectId
            : null;
    }

    private function hydrate(object $row): DeletionRequest
    {
        return new DeletionRequest(
            id: (string) $row->id,
            subjectType: (string) $row->subject_type,
            subjectId: (string) $row->subject_id,
            requestedAt: new \DateTimeImmutable((string) $row->requested_at, new \DateTimeZone('UTC')),
            executeAfter: new \DateTimeImmutable((string) $row->execute_after, new \DateTimeZone('UTC')),
            status: DeletionStatus::from((string) $row->status),
            requestedVia: $row->requested_via === null ? null : (string) $row->requested_via,
            resolvedAt: $row->resolved_at === null
                ? null
                : new \DateTimeImmutable((string) $row->resolved_at, new \DateTimeZone('UTC')),
            resolvedReason: $row->resolved_reason === null ? null : (string) $row->resolved_reason,
            policyFingerprint: $row->policy_fingerprint === null ? null : (string) $row->policy_fingerprint,
            attempts: (int) $row->attempts,
            suspendedAt: $row->suspended_at === null
                ? null
                : new \DateTimeImmutable((string) $row->suspended_at, new \DateTimeZone('UTC')),
            remindersSent: $this->decodeReminders($row->reminders_sent ?? null),
            footprintJson: isset($row->footprint) ? (string) $row->footprint : null,
            verificationJson: isset($row->verification) ? (string) $row->verification : null,
        );
    }

    /** @return list<int> */
    private function decodeReminders(mixed $raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values(array_map(intval(...), $decoded)) : [];
    }
}
