<?php

declare(strict_types=1);

namespace PrivacyCI\Discovery\Scanners;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PrivacyCI\Discovery\Flow\FlowFinding;
use PrivacyCI\Discovery\Flow\KeyPattern;
use PrivacyCI\Discovery\Flow\SubjectReference;
use PrivacyCI\Manifest\LocationKind;
use PrivacyCI\Manifest\Subject;

/**
 * Stage B, infers where subject identifiers are written outside the database.
 *
 *     Cache::put("user:{$userId}", $payload);
 *     Storage::disk('s3')->put("avatars/{$user->id}.jpg", $file);
 *     Redis::set("profile:{$user->id}", $json);
 *
 * Precision here is inherently poor: PHP interpolates dynamically, applications
 * wrap everything in repositories and helpers, and a key is only recognisable as
 * personal by how it is *named*. That is why every finding is Linkage::Inferred
 * and may only ever warn, a false positive that blocks a deploy costs the
 * customer permanently, while a missed Redis key costs one review.
 */
final class StaticFlowScanner
{
    /**
     * Facade/method pairs that write somewhere durable, and what kind of store
     * that is. The key argument is index 0 in every case listed here.
     *
     * @var array<string, array{0: LocationKind, 1: string, 2: list<string>}>
     */
    private const WRITERS = [
        'Cache' => [LocationKind::RedisKey, 'cache', ['put', 'forever', 'add', 'remember', 'rememberForever', 'set']],
        'Redis' => [LocationKind::RedisKey, 'redis', ['set', 'setex', 'hset', 'hmset', 'lpush', 'rpush', 'sadd', 'zadd']],
        'Storage' => [LocationKind::ObjectStorage, 'storage', ['put', 'putFile', 'putFileAs', 'append', 'prepend', 'move', 'copy']],
        'Session' => [LocationKind::RedisKey, 'session', ['put', 'push']],
    ];

    /** Methods on a chained object, e.g. Storage::disk('s3')->put(...). */
    private const CHAINED = ['put', 'putFile', 'putFileAs', 'set', 'setex', 'forever', 'add'];

    private readonly Parser $parser;

    private readonly NodeFinder $finder;

    private readonly NodeTraverser $traverser;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
        $this->finder = new NodeFinder;
        $this->traverser = new NodeTraverser(new NameResolver);
    }

    /**
     * @param  list<string>  $paths
     * @return list<FlowFinding>
     */
    public function scan(array $paths, Subject $subject): array
    {
        $matcher = new SubjectReference($subject);

        /** @var array<string, FlowFinding> $found */
        $found = [];

        foreach ($paths as $path) {
            foreach ($this->phpFilesIn($path) as $file) {
                foreach ($this->scanFile($file, $matcher) as $finding) {
                    $key = $finding->kind->value.':'.$finding->store.':'.$finding->pattern;

                    // The same cache key written from three places is one
                    // location, not three findings.
                    if (! isset($found[$key]) || $found[$key]->confidence < $finding->confidence) {
                        $found[$key] = $finding;
                    }
                }
            }
        }

        $findings = array_values($found);
        usort($findings, static fn (FlowFinding $a, FlowFinding $b): int => $a->pattern <=> $b->pattern);

        return $findings;
    }

    /** @return list<FlowFinding> */
    private function scanFile(string $file, SubjectReference $matcher): array
    {
        $code = @file_get_contents($file);

        if ($code === false) {
            return [];
        }

        try {
            $ast = $this->traverser->traverse((array) $this->parser->parse($code));
        } catch (\Throwable) {
            return [];
        }

        $findings = [];
        $relative = basename($file);

        /** @var list<Node\Expr\StaticCall> $statics */
        $statics = $this->finder->findInstanceOf($ast, Node\Expr\StaticCall::class);

        foreach ($statics as $call) {
            $finding = $this->fromStaticCall($call, $matcher, $relative);

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        /** @var list<Node\Expr\MethodCall> $methods */
        $methods = $this->finder->findInstanceOf($ast, Node\Expr\MethodCall::class);

        foreach ($methods as $call) {
            $finding = $this->fromChainedCall($call, $matcher, $relative);

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    private function fromStaticCall(
        Node\Expr\StaticCall $call,
        SubjectReference $matcher,
        string $file,
    ): ?FlowFinding {
        if (! $call->class instanceof Node\Name || ! $call->name instanceof Node\Identifier) {
            return null;
        }

        $facade = $call->class->getLast();
        $method = $call->name->toString();

        if (! isset(self::WRITERS[$facade])) {
            return null;
        }

        [$kind, $store, $methods] = self::WRITERS[$facade];

        if (! in_array($method, $methods, true)) {
            return null;
        }

        return $this->build(
            $kind,
            $store,
            $call->getArgs()[0]->value ?? null,
            $matcher,
            "{$facade}::{$method}() in {$file}",
        );
    }

    /**
     * Handles Storage::disk('s3')->put(...) and Cache::tags(...)->put(...),
     * attributing the write to the named disk or connection rather than the default.
     */
    private function fromChainedCall(
        Node\Expr\MethodCall $call,
        SubjectReference $matcher,
        string $file,
    ): ?FlowFinding {
        if (! $call->name instanceof Node\Identifier
            || ! in_array($call->name->toString(), self::CHAINED, true)) {
            return null;
        }

        $root = $call->var;

        while ($root instanceof Node\Expr\MethodCall) {
            $root = $root->var;
        }

        if (! $root instanceof Node\Expr\StaticCall
            || ! $root->class instanceof Node\Name
            || ! $root->name instanceof Node\Identifier) {
            return null;
        }

        $facade = $root->class->getLast();

        if (! isset(self::WRITERS[$facade])) {
            return null;
        }

        [$kind, $store] = self::WRITERS[$facade];

        // Storage::disk('s3') / Redis::connection('cache') names the store.
        $named = $root->getArgs()[0]->value ?? null;

        if ($named instanceof Node\Scalar\String_) {
            $store = $named->value;
        }

        $method = $call->name->toString();

        return $this->build(
            $kind,
            $store,
            $call->getArgs()[0]->value ?? null,
            $matcher,
            "{$facade}::{$root->name->toString()}()->{$method}() in {$file}",
        );
    }

    private function build(
        LocationKind $kind,
        string $store,
        ?Node $keyArg,
        SubjectReference $matcher,
        string $origin,
    ): ?FlowFinding {
        $key = KeyPattern::from($keyArg);

        if ($key === null || $key['refs'] === []) {
            // A wholly literal key cannot be keyed on a person.
            return null;
        }

        $match = $matcher->match($key['refs']);

        if ($match === null) {
            return null;
        }

        return new FlowFinding(
            kind: $kind,
            store: $store,
            // Normalise the subject's placeholder to {id}. Without this the same
            // Redis key shows up twice, once as user:{userId} from the code and
            // once as user:{id} from the policy that declares it, and a developer
            // has to classify both to silence one.
            pattern: str_replace('{'.$match['matched'].'}', '{id}', $key['pattern']),
            confidence: $match['confidence'],
            evidence: [
                $origin,
                "key interpolates {$match['matched']}",
                'inferred by static analysis, verify before relying on it',
            ],
        );
    }

    /** @return list<string> */
    private function phpFilesIn(string $path): array
    {
        if (! is_dir($path)) {
            return [];
        }

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
}
