<?php

declare(strict_types=1);

namespace PrivacyCI\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PrivacyCI\Discovery\Scanners\ModelScanner;
use PrivacyCI\Generation\NamespacePath;

/**
 * Applications that organise code by domain rather than by Laravel's default
 * layout keep models under their own namespace, outside app/Models.
 */
final class ModelNamespaceTest extends TestCase
{
    private const DIR = __DIR__.'/../fixtures/DomainModels';

    #[Test]
    public function models_are_found_under_any_namespace(): void
    {
        $map = (new ModelScanner)->scan([self::DIR]);

        $this->assertNotNull($map->forClass('Acme\Shop\Domain\Models\Member'));
        $this->assertSame('members', $map->forClass('Acme\Shop\Domain\Models\Member')?->table);
    }

    #[Test]
    public function relationships_resolve_across_a_deep_namespace(): void
    {
        $receipt = (new ModelScanner)->scan([self::DIR])->forClass('Acme\Shop\Domain\Models\Receipt');

        $this->assertSame('Acme\Shop\Domain\Models\Member', $receipt?->relations[0]->relatedClass);
        $this->assertSame('member_id', $receipt?->relations[0]->foreignKey);
    }

    #[Test]
    public function a_policy_may_name_a_model_by_its_short_name(): void
    {
        $this->assertSame('members', (new ModelScanner)->scan([self::DIR])->resolveTable('Member'));
    }

    #[Test]
    public function a_base_class_outside_the_scanned_paths_is_missed_by_default(): void
    {
        // Invoice extends a class that is neither an Eloquent base nor named
        // "*Model" nor present in the scan, so ancestry cannot place it.
        $this->assertNull(
            (new ModelScanner)->scan([self::DIR])->forClass('Acme\Shop\Domain\Models\Invoice'),
        );
    }

    #[Test]
    public function declaring_the_base_class_makes_it_visible(): void
    {
        $map = (new ModelScanner)
            ->withBaseClasses(['Acme\Support\Database\Entity'])
            ->scan([self::DIR]);

        $invoice = $map->forClass('Acme\Shop\Domain\Models\Invoice');

        $this->assertNotNull($invoice, 'model_base_classes is the escape hatch');
        $this->assertSame('invoices', $invoice->table);
    }

    #[Test]
    public function generated_files_follow_the_applications_psr4_map(): void
    {
        $paths = new NamespacePath(['App\\' => 'app/', 'Acme\\Shop\\' => 'src/']);

        // Assuming App\ => app/ would put this in app/Acme/Shop/... where the
        // autoloader would never find it.
        $this->assertSame(
            'src/Domain/Privacy/DeleteMember.php',
            $paths->fileFor('Acme\Shop\Domain\Privacy\DeleteMember', 'app/Privacy'),
        );
    }

    #[Test]
    public function the_longest_matching_prefix_wins(): void
    {
        $paths = new NamespacePath(['Acme\\' => 'lib/', 'Acme\\Shop\\' => 'src/']);

        $this->assertSame(
            'src/Thing.php',
            $paths->fileFor('Acme\Shop\Thing', 'app/Privacy'),
        );
    }

    #[Test]
    public function an_unmapped_namespace_falls_back_rather_than_guessing(): void
    {
        $paths = new NamespacePath(['App\\' => 'app/']);

        $this->assertSame(
            'app/Privacy/DeleteThing.php',
            $paths->fileFor('Unmapped\Deep\DeleteThing', 'app/Privacy'),
        );
    }
}
