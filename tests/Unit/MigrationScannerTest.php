<?php

declare(strict_types=1);

namespace PrivacyCI\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PrivacyCI\Discovery\Scanners\MigrationScanner;
use PrivacyCI\Discovery\Schema\SchemaMap;

final class MigrationScannerTest extends TestCase
{
    private function scan(): SchemaMap
    {
        return (new MigrationScanner)->scan([__DIR__.'/../fixtures/migrations']);
    }

    #[Test]
    public function it_reconstructs_tables_from_migration_files(): void
    {
        $schema = $this->scan();

        $this->assertSame(
            ['comments', 'orders', 'recommendation_events', 'sessions', 'subscribers', 'users'],
            array_keys($schema->tables()),
        );
    }

    #[Test]
    public function it_reads_up_and_ignores_down(): void
    {
        // Every real migration drops in down() exactly what it created in up().
        // Scanning the whole file creates each table and then deletes it, which
        // silently produced an empty schema and zero findings.
        $schema = $this->scan();

        $this->assertTrue($schema->hasTable('sessions'), 'down() must not undo up()');
        $this->assertTrue($schema->table('sessions')->hasColumn('ip_address'));
    }

    #[Test]
    public function it_expands_zero_argument_helpers(): void
    {
        $users = $this->scan()->table('users');

        foreach (['id', 'created_at', 'updated_at', 'deleted_at'] as $column) {
            $this->assertTrue($users->hasColumn($column), "missing {$column}");
        }
    }

    #[Test]
    public function it_replays_history_rather_than_unioning_it(): void
    {
        $schema = $this->scan();

        // Added in 2019 by rememberToken(), dropped in 2021.
        $this->assertFalse($schema->table('users')->hasColumn('remember_token'));

        // Created and dropped within a single migration.
        $this->assertFalse($schema->hasTable('legacy_profiles'));

        // Added by a later Schema::table() call.
        $this->assertTrue($schema->table('users')->hasColumn('phone'));
    }

    #[Test]
    public function it_preserves_modifiers_across_a_method_chain(): void
    {
        $users = $this->scan()->table('users');

        $this->assertTrue($users->column('phone')?->nullable, 'phone should be nullable');
        $this->assertFalse($users->column('email')?->nullable, 'email should not be nullable');
    }

    #[Test]
    public function it_resolves_foreign_ids_by_convention(): void
    {
        $key = $this->scan()->table('comments')->foreignKeyFor('user_id');

        $this->assertNotNull($key);
        $this->assertSame('users', $key->referencesTable);
        $this->assertSame('id', $key->referencesColumn);
    }

    #[Test]
    public function it_resolves_explicit_foreign_key_declarations(): void
    {
        $key = $this->scan()->table('orders')->foreignKeyFor('buyer_id');

        $this->assertNotNull($key, 'foreign()->references()->on() should be detected');
        $this->assertSame('users', $key->referencesTable);
    }

    #[Test]
    public function it_expands_morphs_into_both_columns(): void
    {
        $events = $this->scan()->table('recommendation_events');

        $this->assertTrue($events->hasColumn('subject_id'));
        $this->assertTrue($events->hasColumn('subject_type'));
    }

    #[Test]
    public function it_records_the_migration_a_column_came_from(): void
    {
        $phone = $this->scan()->table('users')->column('phone');

        $this->assertSame('2021_01_01_000000_add_phone_to_users.php', $phone?->definedIn);
    }
}
