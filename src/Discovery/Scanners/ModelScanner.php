<?php

declare(strict_types=1);

namespace PrivacyCI\Discovery\Scanners;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PrivacyCI\Discovery\Models\ModelDefinition;
use PrivacyCI\Discovery\Models\ModelMap;
use PrivacyCI\Discovery\Models\Relation;

/**
 * Reads Eloquent models statically: table name, $fillable/$hidden/$casts, and
 * declared relationships.
 *
 * This earns its place by catching associations the database does not know about.
 * Plenty of production schemas declare `belongsTo(User::class)` with no matching
 * foreign-key constraint. The link is real, the migration scanner cannot see
 * it, and it is still deterministic enough to fail a build on.
 */
final class ModelScanner
{
    /**
     * Eloquent base classes, fully qualified. `Authenticatable` is an alias for
     * Illuminate\Foundation\Auth\User, so after name resolution it arrives here
     * as a class whose short name is "User", matching on the alias would miss it.
     */
    private const BASE_CLASSES = [
        'Illuminate\\Database\\Eloquent\\Model',
        'Illuminate\\Foundation\\Auth\\User',
    ];

    /**
     * Scout's trait, fully qualified.
     *
     * Matched on the resolved name only. An application's own `Searchable`
     * trait is a different thing entirely, and a Scout finding is deterministic
     * enough to fail a build, so guessing from the short name would let an
     * unrelated trait block someone's deploy.
     */
    private const SCOUT_TRAIT = 'Laravel\\Scout\\Searchable';

    private const RELATION_METHODS = [
        'belongsTo', 'hasMany', 'hasOne', 'belongsToMany',
        'morphTo', 'morphMany', 'morphOne', 'hasManyThrough', 'hasOneThrough',
    ];

    private readonly Parser $parser;

    private readonly NodeFinder $finder;

    private readonly NodeTraverser $traverser;

    /** @var list<string> Extra base classes this application's models extend. */
    private array $extraBases = [];

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
        $this->finder = new NodeFinder;

        // Without name resolution, `belongsTo(User::class)` inside App\Models\Comment
        // yields the literal "User" rather than "App\Models\User", and relationships
        // silently fail to join up across files.
        $this->traverser = new NodeTraverser(new NameResolver);
    }

    /**
     * Teach the scanner about a base class it would not otherwise recognise.
     *
     * Ancestry usually resolves this on its own, but only when the base class
     * is inside a scanned path. An application whose models extend something
     * living in a package, and not named `*Model`, needs to say so or every
     * one of its models is silently invisible.
     *
     * @param  list<string>  $classes
     */
    public function withBaseClasses(array $classes): self
    {
        $clone = clone $this;
        $clone->extraBases = array_values(array_filter(
            array_map(static fn (string $c): string => ltrim($c, '\\'), $classes),
        ));

        return $clone;
    }

    /** @param list<string> $paths Directories to search recursively for models. */
    public function scan(array $paths): ModelMap
    {
        /** @var array<string, array{parent: ?string, model: ModelDefinition}> $candidates */
        $candidates = [];

        foreach ($paths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            foreach ($this->phpFilesIn($path) as $file) {
                $candidate = $this->parseFile($file);

                if ($candidate !== null) {
                    $candidates[$candidate['model']->class] = $candidate;
                }
            }
        }

        // Second pass: a class is a model if its ancestry reaches an Eloquent base.
        // One hop is not enough, plenty of applications put an App\Models\BaseModel
        // in between, and every one of their models would otherwise be invisible.
        $map = new ModelMap;

        foreach ($candidates as $candidate) {
            if (! $this->descendsFromModel($candidate['parent'], $candidates)) {
                continue;
            }

            $map->add($this->withInheritedSearch($candidate, $candidates));
        }

        return $map;
    }

    /**
     * @param  array<string, array{parent: ?string, model: ModelDefinition}>  $candidates
     */
    private function descendsFromModel(?string $parent, array $candidates, int $depth = 0): bool
    {
        if ($parent === null || $depth > 10) {
            return false;
        }

        if (in_array($parent, self::BASE_CLASSES, true)
            || in_array($parent, $this->extraBases, true)) {
            return true;
        }

        // An unresolvable parent whose name still looks like a model base is
        // accepted: better to scan a class that turns out not to be a model than
        // to silently skip a real one.
        if (! isset($candidates[$parent])) {
            return str_ends_with($parent, 'Model');
        }

        return $this->descendsFromModel($candidates[$parent]['parent'], $candidates, $depth + 1);
    }

    /** @return list<string> */
    private function phpFilesIn(string $path): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /** @return array{parent: ?string, model: ModelDefinition}|null */
    private function parseFile(string $file): ?array
    {
        $code = @file_get_contents($file);

        if ($code === false) {
            return null;
        }

        try {
            $ast = $this->parser->parse($code);
        } catch (\Throwable) {
            return null;
        }

        if ($ast === null) {
            return null;
        }

        $ast = $this->traverser->traverse($ast);

        $class = $this->finder->findFirstInstanceOf($ast, Node\Stmt\Class_::class);

        if (! $class instanceof Node\Stmt\Class_ || $class->name === null) {
            return null;
        }

        $name = $class->name->toString();
        $fqcn = $class->namespacedName?->toString() ?? $name;

        $table = $this->stringProperty($class, 'table') ?? Inflector::tableName($name);

        $searchable = $this->usesScout($class);

        return [
            'parent' => $class->extends?->toString(),
            'model' => new ModelDefinition(
                class: $fqcn,
                table: $table,
                relations: $this->relations($class),
                fillable: $this->arrayProperty($class, 'fillable'),
                hidden: $this->arrayProperty($class, 'hidden'),
                casts: $this->casts($class),
                definedIn: basename($file),
                searchable: $searchable,
                searchableAs: $this->searchableAs($class),
                searchableFields: $this->searchableFields($class),
            ),
        ];
    }

    /** @return list<Relation> */
    private function relations(Node\Stmt\Class_ $class): array
    {
        $relations = [];

        foreach ($class->getMethods() as $method) {
            /** @var list<Node\Expr\MethodCall> $calls */
            $calls = $this->finder->findInstanceOf((array) $method->stmts, Node\Expr\MethodCall::class);

            foreach ($calls as $call) {
                if (! $call->var instanceof Node\Expr\Variable
                    || $call->var->name !== 'this'
                    || ! $call->name instanceof Node\Identifier) {
                    continue;
                }

                $type = $call->name->toString();

                if (! in_array($type, self::RELATION_METHODS, true)) {
                    continue;
                }

                $args = $call->getArgs();
                $related = isset($args[0]) ? $this->classValue($args[0]->value) : null;

                if ($related === null) {
                    continue;
                }

                $relations[] = new Relation(
                    method: $method->name->toString(),
                    type: $type,
                    relatedClass: $related,
                    foreignKey: isset($args[1]) ? $this->stringValue($args[1]->value) : null,
                );

                break;
            }
        }

        return $relations;
    }

    /** @return array<string, string> */
    private function casts(Node\Stmt\Class_ $class): array
    {
        $node = $this->propertyValue($class, 'casts');

        // Laravel 11 moved casts to a casts() method; support both shapes.
        if ($node === null) {
            foreach ($class->getMethods() as $method) {
                if ($method->name->toString() !== 'casts') {
                    continue;
                }

                $return = $this->finder->findFirstInstanceOf((array) $method->stmts, Node\Stmt\Return_::class);
                $node = $return instanceof Node\Stmt\Return_ ? $return->expr : null;
                break;
            }
        }

        if (! $node instanceof Node\Expr\Array_) {
            return [];
        }

        $casts = [];

        foreach ($node->items as $item) {
            if ($item === null || $item->key === null) {
                continue;
            }

            $key = $this->stringValue($item->key);
            $value = $this->stringValue($item->value) ?? $this->classValue($item->value);

            if ($key !== null && $value !== null) {
                $casts[$key] = $value;
            }
        }

        return $casts;
    }

    /** @return list<string> */
    private function arrayProperty(Node\Stmt\Class_ $class, string $name): array
    {
        $node = $this->propertyValue($class, $name);

        if (! $node instanceof Node\Expr\Array_) {
            return [];
        }

        $values = [];

        foreach ($node->items as $item) {
            if ($item !== null && ($value = $this->stringValue($item->value)) !== null) {
                $values[] = $value;
            }
        }

        return $values;
    }

    private function stringProperty(Node\Stmt\Class_ $class, string $name): ?string
    {
        return $this->stringValue($this->propertyValue($class, $name));
    }

    private function propertyValue(Node\Stmt\Class_ $class, string $name): ?Node\Expr
    {
        foreach ($class->getProperties() as $property) {
            foreach ($property->props as $prop) {
                if ($prop->name->toString() === $name) {
                    return $prop->default;
                }
            }
        }

        return null;
    }

    /**
     * Scout's trait reached through a base class as well as directly.
     *
     * `class Post extends BaseModel` where BaseModel uses Searchable is still an
     * indexed model, and it is the shape most likely to be missed by hand.
     *
     * @param  array{parent: ?string, model: ModelDefinition}                  $candidate
     * @param  array<string, array{parent: ?string, model: ModelDefinition}>  $candidates
     */
    private function withInheritedSearch(array $candidate, array $candidates): ModelDefinition
    {
        $model = $candidate['model'];

        if ($model->searchable) {
            return $model;
        }

        $parent = $candidate['parent'];

        for ($depth = 0; $parent !== null && $depth <= 10; $depth++) {
            $ancestor = $candidates[$parent] ?? null;

            if ($ancestor === null) {
                return $model;
            }

            if ($ancestor['model']->searchable) {
                // searchableAs() is inherited only when the base states one
                // literally; otherwise Scout falls back to *this* model's table.
                return $model->withSearchable(
                    $ancestor['model']->searchableAs,
                    $ancestor['model']->searchableFields,
                );
            }

            $parent = $ancestor['parent'];
        }

        return $model;
    }

    private function usesScout(Node\Stmt\Class_ $class): bool
    {
        foreach ($class->stmts as $stmt) {
            if (! $stmt instanceof Node\Stmt\TraitUse) {
                continue;
            }

            foreach ($stmt->traits as $trait) {
                if ($trait->toString() === self::SCOUT_TRAIT) {
                    return true;
                }
            }
        }

        return false;
    }

    /** The index named by `searchableAs()`, when it returns a plain string. */
    private function searchableAs(Node\Stmt\Class_ $class): ?string
    {
        $return = $this->returnExpr($class, 'searchableAs');

        return $this->stringValue($return);
    }

    /**
     * The columns `toSearchableArray()` copies into the index.
     *
     * Evidence, not a location: it is what lets a report say *which* personal
     * columns left the database. An absent method is not an absent risk, Scout
     * then indexes the model's whole toArray(), which is the wider exposure.
     *
     * @return list<string>
     */
    private function searchableFields(Node\Stmt\Class_ $class): array
    {
        $return = $this->returnExpr($class, 'toSearchableArray');

        if (! $return instanceof Node\Expr\Array_) {
            return [];
        }

        $fields = [];

        foreach ($return->items as $item) {
            if ($item === null) {
                continue;
            }

            $key = $item->key === null ? null : $this->stringValue($item->key);

            if ($key !== null) {
                $fields[] = $key;
            }
        }

        sort($fields);

        return array_values(array_unique($fields));
    }

    private function returnExpr(Node\Stmt\Class_ $class, string $method): ?Node\Expr
    {
        foreach ($class->getMethods() as $candidate) {
            if ($candidate->name->toString() !== $method) {
                continue;
            }

            $return = $this->finder->findFirstInstanceOf(
                (array) $candidate->stmts,
                Node\Stmt\Return_::class,
            );

            return $return instanceof Node\Stmt\Return_ ? $return->expr : null;
        }

        return null;
    }

    private function stringValue(?Node $node): ?string
    {
        return $node instanceof Node\Scalar\String_ ? $node->value : null;
    }

    /** Resolves `User::class` and the plain-string form alike. */
    private function classValue(?Node $node): ?string
    {
        if ($node instanceof Node\Expr\ClassConstFetch
            && $node->class instanceof Node\Name
            && $node->name instanceof Node\Identifier
            && $node->name->toString() === 'class') {
            return $node->class->toString();
        }

        return $this->stringValue($node);
    }
}
