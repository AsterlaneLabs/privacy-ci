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

final class HandlerGeneratorTest extends TestCase
{
    private Subject $subject;

    protected function setUp(): void
    {
        require_once __DIR__.'/../fixtures/Policies/UserPrivacyPolicy.php';
        $this->subject = new Subject('user', 'users.id');
    }

    private function generate(): string
    {
        $migrations = [__DIR__.'/../fixtures/migrations'];
        $models = (new ModelScanner)->scan([__DIR__.'/../fixtures/Models']);

        $manifest = (new Discoverer)->discover(
            project: 'fixture/app',
            migrationPaths: $migrations,
            subject: $this->subject,
            modelPaths: [__DIR__.'/../fixtures/Models'],
        );

        $manifest = (new PolicyCompiler($models))->apply($manifest, new \App\Privacy\UserPrivacyPolicy);

        $schema = (new MigrationScanner)->scan($migrations);
        $depth = [];

        foreach ((new ForeignKeyGraph($schema))->reachableFrom($this->subject->rootTable()) as $table => $link) {
            $depth[$table] = $link['hops'];
        }

        $plan = (new HandlerPlanner)->plan($manifest, $this->subject, $depth, $models);

        return (new HandlerGenerator)->generate($plan);
    }

    #[Test]
    public function the_generated_file_is_valid_php(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'handler').'.php';
        file_put_contents($file, $this->generate());

        exec('php -l '.escapeshellarg($file).' 2>&1', $output, $status);
        unlink($file);

        $this->assertSame(0, $status, implode("\n", $output));
    }

    #[Test]
    public function it_implements_the_deleter_contract(): void
    {
        $code = $this->generate();

        $this->assertStringContainsString('implements SubjectDeleter', $code);
        $this->assertStringContainsString('use PrivacyCI\Lifecycle\SubjectDeleter;', $code);
        $this->assertStringContainsString('public function delete(DeletionRequest $request): void', $code);
    }

    #[Test]
    public function the_subject_row_is_deleted_last(): void
    {
        $code = $this->generate();

        $userPos = strpos($code, 'whereKey($subjectId)->delete()');
        $commentPos = strpos($code, 'Comment::query()');

        $this->assertIsInt($userPos);
        $this->assertIsInt($commentPos);

        // Deleting the parent first would have the database reject everything
        // after it, or orphan the rows that follow.
        $this->assertGreaterThan($commentPos, $userPos, 'the user row must go last');
    }

    #[Test]
    public function deletes_are_chunked(): void
    {
        // One subject can own millions of dependent rows. A single statement
        // over all of them is one transaction that locks, lags and times out.
        $this->assertMatchesRegularExpression(
            '/do \{.*->limit\(1000\).*->delete\(\);.*\} while \(\$deleted > 0\);/s',
            $this->generate(),
        );
    }

    #[Test]
    public function anonymisation_is_chunked_and_says_why_it_terminates(): void
    {
        $code = $this->generate();

        $this->assertStringContainsString('->limit(1000)', $code);
        $this->assertStringContainsString('} while ($affected > 0);', $code);
        $this->assertStringContainsString('is among the columns being nulled', $code);
    }

    #[Test]
    public function the_anonymise_loop_always_nulls_the_column_it_filters_on(): void
    {
        $code = $this->generate();

        // Without this the generated loop never terminates.
        $this->assertStringContainsString("->where('user_id', \$subjectId)", $code);
        $this->assertStringContainsString("'user_id' => null,", $code);
    }

    #[Test]
    public function the_subject_row_is_not_chunked(): void
    {
        // Exactly one row, so a loop would be noise.
        $this->assertStringContainsString('whereKey($subjectId)->delete();', $this->generate());
    }

    #[Test]
    public function anonymise_writes_the_policys_replacement_values(): void
    {
        $code = $this->generate();

        $this->assertStringContainsString('->update([', $code);
        $this->assertStringContainsString("'user_id' => null,", $code);
        $this->assertStringContainsString("'author_ip' => null,", $code);
    }

    #[Test]
    public function retained_tables_explain_themselves_rather_than_acting(): void
    {
        $code = $this->generate();

        $this->assertStringContainsString('statutory accounting retention', $code);
        $this->assertStringNotContainsString('Order::query()->whereKey', $code);
    }

    #[Test]
    public function declared_stores_are_cleared(): void
    {
        $code = $this->generate();

        $this->assertStringContainsString('Redis::del("profile:{$subjectId}");', $code);
        $this->assertStringContainsString("Storage::disk('s3')->delete(\"avatars/{\$subjectId}.jpg\");", $code);
    }

    #[Test]
    public function stores_are_cleared_before_any_row_is_touched(): void
    {
        $code = $this->generate();

        $redis = strpos($code, 'Redis::del');
        $comment = strpos($code, 'Comment::query()');

        // Once the subject's row is gone, a key pattern built from it can no
        // longer be resolved.
        $this->assertLessThan($comment, $redis);
    }

    #[Test]
    public function unclassified_locations_become_loud_todos(): void
    {
        $code = $this->generate();

        $this->assertStringContainsString('TODO', $code);
        // recommendation_events is absent from the fixture policy on purpose.
        $this->assertStringContainsString('recommendation_events.user_id', $code);
    }

    #[Test]
    public function a_table_with_no_model_falls_back_to_the_query_builder(): void
    {
        $code = $this->generate();

        // The bug this guards: modelRef() used to return DB::table('sessions')
        // and the caller appended ::query(), producing invalid PHP that the
        // fixtures never caught because every fixture table had a model.
        $this->assertStringContainsString("DB::table('sessions')", $code);
        $this->assertStringNotContainsString('::query()::query()', $code);
        $this->assertStringNotContainsString(")::query()", $code);
        $this->assertStringContainsString('use Illuminate\\Support\\Facades\\DB;', $code);
    }

    #[Test]
    public function it_says_it_is_generated_and_asks_to_be_reviewed(): void
    {
        $code = $this->generate();

        $this->assertStringContainsString('Generated by `php artisan privacy:make-handler`', $code);
        $this->assertStringContainsString('Review it before merging', $code);
    }

    #[Test]
    public function regenerating_unchanged_input_produces_an_identical_file(): void
    {
        $this->assertSame($this->generate(), $this->generate());
    }
}
