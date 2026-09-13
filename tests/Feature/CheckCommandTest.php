<?php

declare(strict_types=1);

namespace PrivacyCI\Tests\Feature;

use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PrivacyCI\Baseline\Baseline;
use PrivacyCI\PrivacyCIServiceProvider;

final class CheckCommandTest extends TestCase
{
    private string $workspace;

    protected function getPackageProviders($app): array
    {
        return [PrivacyCIServiceProvider::class];
    }

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/privacy-check-'.bin2hex(random_bytes(6));
        mkdir($this->workspace.'/migrations', 0o777, true);
        $this->writeMigration('2019_01_01_000000_create_users_table.php', 'users', <<<'PHP'
            $table->id();
            $table->string('email');
        PHP);
        $this->writeMigration('2019_02_01_000000_create_comments_table.php', 'comments', <<<'PHP'
            $table->id();
            $table->foreignId('user_id')->constrained();
        PHP);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->workspace.'/migrations/*') as $file) {
            if (is_string($file)) {
                unlink($file);
            }
        }
        @unlink($this->baselinePath());
        @rmdir($this->workspace.'/migrations');
        @rmdir($this->workspace);

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('privacy.subjects', ['user' => 'users.id']);
        $app['config']->set('privacy.discovery.migration_paths', [$this->workspace.'/migrations']);
        $app['config']->set('privacy.discovery.model_paths', []);
        $app['config']->set('privacy.discovery.config_paths', []);
        $app['config']->set('privacy.discovery.composer_lock', null);
        $app['config']->set('privacy.ci.baseline', $this->baselinePath());
        $app['config']->set('privacy.lifecycle.schedule', null);
    }

    private function baselinePath(): string
    {
        return $this->workspace.'/privacy-baseline.json';
    }

    private function writeMigration(string $name, string $table, string $body): void
    {
        file_put_contents($this->workspace.'/migrations/'.$name, <<<PHP
        <?php
        use Illuminate\\Database\\Migrations\\Migration;
        use Illuminate\\Database\\Schema\\Blueprint;
        use Illuminate\\Support\\Facades\\Schema;
        return new class extends Migration {
            public function up(): void {
                Schema::create('{$table}', function (Blueprint \$table) {
        {$body}
                });
            }
            public function down(): void { Schema::dropIfExists('{$table}'); }
        };
        PHP);
    }

    #[Test]
    public function it_fails_on_a_fresh_codebase_with_no_baseline(): void
    {
        $this->artisan('privacy:check')->assertFailed();
    }

    #[Test]
    public function baselining_makes_the_same_code_pass(): void
    {
        $this->artisan('privacy:baseline')->assertSuccessful();

        $this->assertFileExists($this->baselinePath());
        $this->artisan('privacy:check')->assertSuccessful();
    }

    #[Test]
    public function a_new_user_linked_column_fails_even_with_a_baseline(): void
    {
        $this->artisan('privacy:baseline')->assertSuccessful();
        $this->artisan('privacy:check')->assertSuccessful();

        // The regression the whole product exists to catch.
        $this->writeMigration('2026_01_01_000000_create_events_table.php', 'recommendation_events', <<<'PHP'
            $table->id();
            $table->foreignId('user_id')->constrained();
        PHP);

        $this->artisan('privacy:check')->assertFailed();
    }

    #[Test]
    public function pre_existing_debt_stays_forgiven_when_something_new_fails(): void
    {
        $this->artisan('privacy:baseline')->assertSuccessful();
        $this->writeMigration('2026_01_01_000000_create_events_table.php', 'recommendation_events', <<<'PHP'
            $table->id();
            $table->foreignId('user_id')->constrained();
        PHP);

        $this->artisan('privacy:check --json')
            ->expectsOutputToContain('recommendation_events.user_id')
            ->assertFailed();

        $baseline = Baseline::load($this->baselinePath());

        $this->assertTrue($baseline->covers('db:primary:comments.user_id'));
        $this->assertFalse($baseline->covers('db:primary:recommendation_events.user_id'));
    }

    #[Test]
    public function warn_only_reports_without_breaking_the_build(): void
    {
        $this->artisan('privacy:check --warn-only')->assertSuccessful();
    }

    #[Test]
    public function fail_on_new_can_be_disabled_in_config(): void
    {
        config()->set('privacy.ci.fail_on_new', false);

        $this->artisan('privacy:check')->assertSuccessful();
    }

    #[Test]
    public function rebaselining_asks_before_forgiving_everything(): void
    {
        $this->artisan('privacy:baseline')->assertSuccessful();

        $this->artisan('privacy:baseline')
            ->expectsConfirmation('Overwrite it? Any unfixed violations will be forgiven.', 'no')
            ->assertSuccessful();
    }

    #[Test]
    public function force_skips_the_confirmation(): void
    {
        $this->artisan('privacy:baseline')->assertSuccessful();
        $this->artisan('privacy:baseline --force')->assertSuccessful();
    }
}
