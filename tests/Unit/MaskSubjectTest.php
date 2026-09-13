<?php

declare(strict_types=1);

namespace PrivacyCI\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PrivacyCI\Discovery\Discoverer;
use PrivacyCI\Discovery\ForeignKeyGraph;
use PrivacyCI\Discovery\Scanners\MigrationScanner;
use PrivacyCI\Discovery\Scanners\ModelScanner;
use PrivacyCI\Generation\HandlerGenerator;
use PrivacyCI\Generation\HandlerPlanner;
use PrivacyCI\Manifest\Subject;
use PrivacyCI\Policy\PolicyCompiler;
use PrivacyCI\Policy\PrivacyPolicy;
use PrivacyCI\Verification\Address;
use PrivacyCI\Verification\Expectation;
use PrivacyCI\Verification\FootprintResolver;

/**
 * Masking the subject's own row: keep it so foreign keys and history survive,
 * and scrub the person out of it.
 */
final class MaskSubjectTest extends TestCase
{
    private Subject $subject;

    protected function setUp(): void
    {
        $this->subject = new Subject('user', 'users.id');
    }

    private function policy(): PrivacyPolicy
    {
        return new class extends PrivacyPolicy
        {
            public function configure(): void
            {
                // Placeholders, because identifying columns are usually NOT NULL.
                $this->anonymize('users', [
                    'email' => 'deleted@example.invalid',
                    'name' => 'Deleted user',
                ]);
            }
        };
    }

    private function manifest(): \PrivacyCI\Manifest\Manifest
    {
        $models = (new ModelScanner)->scan([__DIR__.'/../fixtures/Models']);

        $manifest = (new Discoverer)->discover(
            project: 'fixture/app',
            migrationPaths: [__DIR__.'/../fixtures/migrations'],
            subject: $this->subject,
            modelPaths: [__DIR__.'/../fixtures/Models'],
        );

        return (new PolicyCompiler($models))->apply($manifest, $this->policy());
    }

    private function handler(): string
    {
        $models = (new ModelScanner)->scan([__DIR__.'/../fixtures/Models']);
        $schema = (new MigrationScanner)->scan([__DIR__.'/../fixtures/migrations']);
        $depth = [];

        foreach ((new ForeignKeyGraph($schema))->reachableFrom('users') as $table => $link) {
            $depth[$table] = $link['hops'];
        }

        return (new HandlerGenerator)->generate(
            (new HandlerPlanner)->plan($this->manifest(), $this->subject, $depth, $models),
        );
    }

    private function rootAddress(): Address
    {
        foreach ((new FootprintResolver)->resolve($this->manifest(), $this->subject, '7')->addresses as $a) {
            if (str_starts_with($a->describe, 'users')) {
                return $a;
            }
        }

        $this->fail('no address for the subject root');
    }

    #[Test]
    public function masking_the_subject_does_not_leave_its_key_failing_ci(): void
    {
        $key = $this->manifest()->location('db:primary:users.id');

        // The key is never among the columns to scrub, because it identifies
        // the row that has to survive. Left unclassified it would fail every
        // build forever and need a retain() rule nobody would think to write.
        $this->assertNotNull($key);
        $this->assertTrue($key->classification->isResolved());
        $this->assertFalse($key->blocksBuild());
        $this->assertStringContainsString('subject key', (string) $key->reason);
    }

    #[Test]
    public function deleting_the_subject_still_classifies_its_key_normally(): void
    {
        $models = (new ModelScanner)->scan([__DIR__.'/../fixtures/Models']);

        $manifest = (new Discoverer)->discover(
            project: 'fixture/app',
            migrationPaths: [__DIR__.'/../fixtures/migrations'],
            subject: $this->subject,
            modelPaths: [__DIR__.'/../fixtures/Models'],
        );

        $deleting = new class extends PrivacyPolicy
        {
            public function configure(): void
            {
                $this->delete('users');
            }
        };

        $key = (new PolicyCompiler($models))->apply($manifest, $deleting)->location('db:primary:users.id');

        $this->assertSame(\PrivacyCI\Manifest\Classification::Delete, $key?->classification);
    }

    #[Test]
    public function the_root_row_is_found_by_its_own_key(): void
    {
        // Without this the generated code filtered on a foreign key the root
        // table does not have, producing where('', $subjectId).
        $this->assertStringContainsString("->where('id', \$subjectId)", $this->handler());
        $this->assertStringNotContainsString("->where('', \$subjectId)", $this->handler());
    }

    #[Test]
    public function the_root_update_is_not_looped(): void
    {
        $code = $this->handler();
        $start = strpos($code, 'User::query()');
        $segment = substr($code, (int) $start, 220);

        // The key a row is found by is the one column masking must not change,
        // so a chunk loop here would never end.
        $this->assertStringNotContainsString('while ($affected > 0)', $segment);
    }

    #[Test]
    public function it_writes_the_placeholder_the_policy_declared(): void
    {
        $code = $this->handler();

        $this->assertStringContainsString("'email' => 'deleted@example.invalid',", $code);
        $this->assertStringContainsString("'name' => 'Deleted user',", $code);
    }

    #[Test]
    public function a_masked_row_is_not_expected_to_disappear(): void
    {
        // Asking for absence would report a failure for doing what was asked.
        $this->assertSame(Expectation::Masked, $this->rootAddress()->expectation);
        $this->assertFalse($this->rootAddress()->expectation->expectsAbsence());
    }

    #[Test]
    public function the_check_carries_the_expected_values(): void
    {
        $expected = json_decode((string) $this->rootAddress()->locator['masked'], true);

        $this->assertSame('deleted@example.invalid', $expected['email']);
        $this->assertSame('Deleted user', $expected['name']);
    }

    #[Test]
    public function the_subject_key_is_never_masked_away(): void
    {
        // Nulling the key would orphan every row that points at it.
        $expected = json_decode((string) $this->rootAddress()->locator['masked'], true);

        $this->assertArrayNotHasKey('id', $expected);
    }
}
