<?php

declare(strict_types=1);

namespace PrivacyCI\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PrivacyCI\Lifecycle\DeletionSchedule;
use PrivacyCI\Lifecycle\DeletionStatus;
use PrivacyCI\Lifecycle\FrozenClock;
use PrivacyCI\Lifecycle\Laravel\EloquentDeletionStore;

final class EloquentDeletionStoreTest extends TestCase
{
    private ConnectionInterface $connection;

    private EloquentDeletionStore $store;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();

        $this->connection = $capsule->getConnection();

        // Run the migration we actually ship rather than a copy of it. A
        // hand-written schema here silently drifts from the real one, which is
        // exactly how a missing column reaches production green.
        $container = new Container;
        $container->instance('db', $capsule->getDatabaseManager());
        $container->bind('db.schema', fn () => $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);

        $migration = require __DIR__.'/../../database/migrations'
            .'/2026_01_01_000000_create_privacy_deletion_requests_table.php';
        $migration->up();

        $this->store = new EloquentDeletionStore($this->connection);
        $this->clock = FrozenClock::at('2026-01-01T00:00:00Z');
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
    }

    private function schedule(int $graceDays = 14): DeletionSchedule
    {
        return new DeletionSchedule($this->store, $this->clock, $graceDays);
    }

    #[Test]
    public function it_round_trips_a_request_through_the_database(): void
    {
        $created = $this->schedule()->request('user', '123', via: 'account settings');
        $loaded = $this->store->find($created->id);

        $this->assertNotNull($loaded);
        $this->assertSame('user', $loaded->subjectType);
        $this->assertSame('123', $loaded->subjectId);
        $this->assertSame('account settings', $loaded->requestedVia);
        $this->assertSame(DeletionStatus::PendingDelete, $loaded->status);
        $this->assertEquals($created->executeAfter, $loaded->executeAfter);
    }

    #[Test]
    public function due_respects_the_window_in_sql_not_just_in_php(): void
    {
        $schedule = $this->schedule();
        $schedule->request('user', '123');

        $this->clock->advance('13 days');
        $this->assertSame([], $schedule->due());

        $this->clock->advance('1 day');
        $this->assertCount(1, $schedule->due());
    }

    #[Test]
    public function a_subject_may_request_and_cancel_repeatedly(): void
    {
        $schedule = $this->schedule();

        // The obvious unique index , (subject_type, subject_id, status), would
        // throw on the second cancellation. This is the regression guard.
        foreach (range(1, 3) as $round) {
            $schedule->request('user', '123');
            $schedule->cancel('user', '123', "round {$round}");
            $this->clock->advance('1 day');
        }

        $rows = $this->connection->table('privacy_deletion_requests')->count();

        $this->assertSame(3, $rows, 'each cycle should leave its own audit row');
        $this->assertNull($schedule->pendingFor('user', '123'));
    }

    #[Test]
    public function the_database_refuses_two_open_requests_for_one_subject(): void
    {
        $schedule = $this->schedule();
        $first = $schedule->request('user', '123');

        // Bypass the application-level guard to simulate a concurrent insert.
        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->connection->table('privacy_deletion_requests')->insert([
            'id' => str_repeat('f', 32),
            'subject_type' => 'user',
            'subject_id' => '123',
            'status' => DeletionStatus::PendingDelete->value,
            'requested_at' => '2026-01-01 00:00:00',
            'execute_after' => '2026-02-01 00:00:00',
            'attempts' => 0,
            'open_key' => 'user:123',
        ]);
    }

    #[Test]
    public function cancelling_frees_the_subject_to_request_again(): void
    {
        $schedule = $this->schedule();
        $schedule->request('user', '123');
        $schedule->cancel('user', '123', 'changed their mind');

        $second = $schedule->request('user', '123');

        $this->assertSame(DeletionStatus::PendingDelete, $second->status);
        $this->assertNotNull($this->store->pendingFor('user', '123'));
    }

    #[Test]
    public function suspension_survives_a_round_trip(): void
    {
        $suspender = new class implements \PrivacyCI\Lifecycle\SubjectSuspender
        {
            public function suspend(\PrivacyCI\Lifecycle\DeletionRequest $request): void
            {
            }

            public function reactivate(\PrivacyCI\Lifecycle\DeletionRequest $request): void
            {
            }
        };

        $schedule = new DeletionSchedule(
            $this->store,
            $this->clock,
            14,
            \PrivacyCI\Lifecycle\LifecycleMode::Suspend,
            $suspender,
        );

        $created = $schedule->request('user', '123');
        $loaded = $this->store->find($created->id);

        $this->assertTrue($loaded?->isSuspended(), 'suspended_at must persist');
        $this->assertSame(
            '2026-01-01T00:00:00+00:00',
            $loaded?->suspendedAt?->format(DATE_ATOM),
        );
    }

    #[Test]
    public function separate_subjects_do_not_collide(): void
    {
        $schedule = $this->schedule();
        $schedule->request('user', '123');
        $schedule->request('user', '456');
        $schedule->request('contractor', '123');

        $this->assertCount(3, $this->store->all());
    }
}
