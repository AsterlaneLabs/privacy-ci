<?php

declare(strict_types=1);

namespace PrivacyCI\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PrivacyCI\Discovery\ForeignKeyGraph;
use PrivacyCI\Discovery\Scanners\MigrationScanner;
use PrivacyCI\Discovery\Schema\SchemaMap;
use PrivacyCI\Discovery\Schema\SqlDdlParser;

/**
 * Raw CREATE TABLE inside DB::statement().
 *
 * A scanner that only understands Schema::create() misses these tables entirely,
 * and the table it misses is typically the application's oldest and most
 * important one, everything that references it maps fine, so the gap is silent.
 */
final class SqlDdlParserTest extends TestCase
{
    private function scan(): SchemaMap
    {
        return (new MigrationScanner)->scan([__DIR__.'/../fixtures/legacy-migrations']);
    }

    #[Test]
    public function it_finds_tables_declared_as_raw_sql(): void
    {
        $this->assertTrue($this->scan()->hasTable('legacy_members'));
    }

    #[Test]
    public function it_reads_every_column(): void
    {
        $members = $this->scan()->table('legacy_members');

        foreach (['member_id', 'handle', 'email', 'password', 'first_name', 'last_name'] as $column) {
            $this->assertTrue($members->hasColumn($column), "missing {$column}");
        }
    }

    #[Test]
    public function an_enum_containing_commas_does_not_shred_the_column_list(): void
    {
        // enum('Active','Suspended','Pending Close') has commas that do not
        // separate definitions; splitting naively loses everything after it.
        $members = $this->scan()->table('legacy_members');

        $this->assertTrue($members->hasColumn('state'));
        $this->assertTrue($members->hasColumn('remember_token'), 'the column after the enum');
        $this->assertTrue($members->hasColumn('contactable'), 'the last column');
    }

    #[Test]
    public function it_reads_nullability_from_the_ddl(): void
    {
        $members = $this->scan()->table('legacy_members');

        $this->assertFalse($members->column('email')?->nullable);
        $this->assertTrue($members->column('first_name')?->nullable);
    }

    #[Test]
    public function keys_and_constraints_are_not_mistaken_for_columns(): void
    {
        $columns = array_keys($this->scan()->table('legacy_members')->columns());

        foreach (['PRIMARY', 'UNIQUE', 'KEY', 'U_email', 'CONSTRAINT'] as $noise) {
            $this->assertNotContains($noise, $columns);
        }
    }

    #[Test]
    public function it_reads_inline_foreign_key_constraints(): void
    {
        $key = $this->scan()->table('legacy_notes')->foreignKeyFor('member_id');

        $this->assertNotNull($key);
        $this->assertSame('legacy_members', $key->referencesTable);
        $this->assertSame('member_id', $key->referencesColumn);
    }

    #[Test]
    public function a_non_conventional_root_still_builds_a_graph(): void
    {
        $reachable = (new ForeignKeyGraph($this->scan()))->reachableFrom('legacy_members');

        $this->assertArrayHasKey('legacy_notes', $reachable);
        $this->assertSame('member_id', $reachable['legacy_notes']['column']);
    }

    #[Test]
    public function drop_table_in_raw_sql_is_honoured(): void
    {
        $parser = new SqlDdlParser;

        $this->assertSame(['legacy_thing'], $parser->droppedTables('DROP TABLE IF EXISTS `legacy_thing`;'));
    }

    #[Test]
    public function if_not_exists_is_tolerated(): void
    {
        $this->assertTrue($this->scan()->hasTable('legacy_notes'));
    }
}
