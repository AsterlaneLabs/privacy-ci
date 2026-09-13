<?php

declare(strict_types=1);

namespace PrivacyCI\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PrivacyCI\Discovery\Models\ModelMap;
use PrivacyCI\Discovery\Scanners\ModelScanner;

final class ModelScannerTest extends TestCase
{
    private function scan(): ModelMap
    {
        return (new ModelScanner)->scan([__DIR__.'/../fixtures/Models']);
    }

    #[Test]
    public function it_finds_models_and_skips_plain_classes(): void
    {
        $classes = array_keys($this->scan()->all());

        $this->assertSame([
            'App\Models\AuditEntry',
            'App\Models\BaseModel',
            'App\Models\Comment',
            'App\Models\Order',
            'App\Models\Subscriber',
            'App\Models\User',
        ], $classes);
    }

    #[Test]
    public function it_follows_ancestry_through_an_intermediate_base_class(): void
    {
        $subscriber = $this->scan()->forClass('App\Models\Subscriber');

        $this->assertNotNull(
            $subscriber,
            'Subscriber extends BaseModel extends Model and must still be found',
        );
        $this->assertSame('subscribers', $subscriber->table);
    }

    #[Test]
    public function it_resolves_table_names_by_convention_and_declaration(): void
    {
        $map = $this->scan();

        $this->assertSame('users', $map->forClass('App\Models\User')?->table);
        $this->assertSame('audit_entries', $map->forClass('App\Models\AuditEntry')?->table);
        // Order declares $table explicitly.
        $this->assertSame('orders', $map->forClass('App\Models\Order')?->table);
    }

    #[Test]
    public function it_resolves_models_by_short_name_for_policy_targets(): void
    {
        $this->assertSame('users', $this->scan()->resolveTable('User'));
        $this->assertSame('orders', $this->scan()->resolveTable('App\Models\Order'));
        // Unknown target falls back to Eloquent's convention.
        $this->assertSame('invoices', $this->scan()->resolveTable('Invoice'));
    }

    #[Test]
    public function it_reads_relationships_with_explicit_foreign_keys(): void
    {
        $comment = $this->scan()->forClass('App\Models\Comment');

        $this->assertCount(1, $comment->relations);
        $this->assertSame('belongsTo', $comment->relations[0]->type);
        $this->assertSame('App\Models\User', $comment->relations[0]->relatedClass);
        $this->assertSame('user_id', $comment->relations[0]->foreignKey);
        $this->assertTrue($comment->relations[0]->keyIsLocal());
    }

    #[Test]
    public function has_many_keys_belong_to_the_related_table_not_this_one(): void
    {
        $user = $this->scan()->forClass('App\Models\User');

        foreach ($user->relations as $relation) {
            $this->assertSame('hasMany', $relation->type);
            $this->assertFalse(
                $relation->keyIsLocal(),
                'a hasMany key lives on the related table',
            );
        }
    }

    #[Test]
    public function it_reads_fillable_hidden_and_casts(): void
    {
        $user = $this->scan()->forClass('App\Models\User');

        $this->assertSame(['name', 'email', 'phone', 'password'], $user->fillable);
        $this->assertSame(['password', 'remember_token'], $user->hidden);
        $this->assertSame('encrypted', $user->casts['phone'] ?? null);
    }

    #[Test]
    public function it_reads_casts_from_the_property_form_too(): void
    {
        $order = $this->scan()->forClass('App\Models\Order');

        $this->assertSame('encrypted', $order->casts['shipping_address'] ?? null);
    }

    #[Test]
    public function hidden_and_encrypted_columns_are_flagged_sensitive(): void
    {
        $user = $this->scan()->forClass('App\Models\User');

        $this->assertTrue($user->looksSensitive('password'), 'in $hidden');
        $this->assertTrue($user->looksSensitive('phone'), 'encrypted cast');
        $this->assertFalse($user->looksSensitive('name'));
    }
}
