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
 * Finds documents written into Elasticsearch or OpenSearch through the SDK.
 *
 * Scout's trait is the tidy case and not the common one: Elasticsearch has not
 * been a first-party Scout engine since Scout 3, so most Laravel applications
 * that index into a cluster do it through the client directly, wrapped in a
 * repository nobody thought to call a privacy boundary.
 *
 * Matching on the *call* does not work. Real code looks like this:
 *
 *     // one method builds the request
 *     $params['index'] = $index;
 *     $params['id'] = $user->user_id;
 *     $params['body']['username'] = $user->username;
 *
 *     // a different method, often in a different file, sends it
 *     $this->client->index($params);
 *
 * Following `$params` across that boundary needs interprocedural dataflow. So we
 * match the *request shape* instead, which is the thing that cannot be avoided:
 * an array carrying both an `index` and an `id`, where the id names the subject.
 * That is an indexed document wherever it is eventually sent, and it reads the
 * same whether the array is a literal in the call or built a key at a time.
 *
 * Everything here is Linkage::Inferred and may only ever warn.
 */
final class SearchFlowScanner
{
    /** Keys that mean "this is the document id". */
    private const ID_KEYS = ['id', '_id'];

    /**
     * Calls whose argument is an indexing request.
     *
     * Used only to recognise the request when the file itself never names the
     * engine; the shape match does the real work.
     *
     * @var list<string>
     */
    private const WRITE_METHODS = ['index', 'bulk', 'create', 'update', 'delete'];

    /** Words that make a file plausibly about a search cluster. */
    private const ENGINE_WORDS = ['opensearch', 'elasticsearch', 'elastic'];

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
     * @param  string        $store  The cluster, per the installed client.
     * @return list<FlowFinding>
     */
    public function scan(array $paths, Subject $subject, string $store = 'scout'): array
    {
        $matcher = new SubjectReference($subject);

        /** @var array<string, FlowFinding> $found */
        $found = [];

        foreach ($paths as $path) {
            foreach ($this->phpFilesIn($path) as $file) {
                foreach ($this->scanFile($file, $matcher, $store) as $finding) {
                    $key = $finding->store.':'.$finding->pattern;

                    // The same document written from three places is one
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
    private function scanFile(string $file, SubjectReference $matcher, string $store): array
    {
        $code = @file_get_contents($file);

        if ($code === false) {
            return [];
        }

        $namesEngine = $this->namesAnEngine($code);

        try {
            $ast = $this->traverser->traverse((array) $this->parser->parse($code));
        } catch (\Throwable) {
            return [];
        }

        $findings = [];
        $relative = basename($file);

        foreach ($this->requests($ast, $namesEngine) as $request) {
            $finding = $this->build($request, $matcher, $store, $relative);

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Every index/id pair in the file, from either shape.
     *
     * @param  array<Node>  $ast
     * @return list<array{index: Node, id: Node, body: list<string>, origin: string}>
     */
    private function requests(array $ast, bool $namesEngine): array
    {
        $requests = [];

        // Shape one: a literal in the call, ->index(['index' => .., 'id' => ..]).
        /** @var list<Node\Expr\Array_> $arrays */
        $arrays = $this->finder->findInstanceOf($ast, Node\Expr\Array_::class);

        foreach ($arrays as $array) {
            $parsed = $this->fromLiteral($array);

            if ($parsed === null) {
                continue;
            }

            // A literal is only a request if something says so: the file names
            // an engine, or the array is the argument of a write call.
            if (! $namesEngine && ! $this->isWriteArgument($array, $ast)) {
                continue;
            }

            $requests[] = $parsed;
        }

        // Shape two: built a key at a time. Only inside a file that names an
        // engine, because $params['id'] on its own is far too common a shape.
        if ($namesEngine) {
            foreach ($this->fromAssignments($ast) as $parsed) {
                $requests[] = $parsed;
            }
        }

        return $requests;
    }

    /**
     * @return array{index: Node, id: Node, body: list<string>, origin: string}|null
     */
    private function fromLiteral(Node\Expr\Array_ $array): ?array
    {
        $index = $id = null;
        $body = [];

        foreach ($array->items as $item) {
            if ($item === null || ! $item->key instanceof Node\Scalar\String_) {
                continue;
            }

            $key = strtolower($item->key->value);

            if ($key === 'index') {
                $index = $item->value;
            } elseif (in_array($key, self::ID_KEYS, true)) {
                $id = $item->value;
            } elseif ($key === 'body' && $item->value instanceof Node\Expr\Array_) {
                $body = $this->literalKeys($item->value);
            }
        }

        // Both halves, or it is not an addressable document. An array with an
        // `id` and no `index` is far more often something else entirely:
        // getRouteParameters() returning ['id' => $model['user_id'], ...] for a
        // URL matched here until this line existed.
        if ($id === null || $index === null) {
            return null;
        }

        return ['index' => $index, 'id' => $id, 'body' => $body, 'origin' => 'request array'];
    }

    /**
     * Requests assembled by assignment, $params['index'] = ..; $params['id'] = ..
     *
     * @param  array<Node>  $ast
     * @return list<array{index: Node, id: Node, body: list<string>, origin: string}>
     */
    private function fromAssignments(array $ast): array
    {
        /** @var array<string, array{index: ?Node, id: ?Node, body: list<string>, origin: string}> $built */
        $built = [];

        /** @var list<Node\Expr\Assign> $assigns */
        $assigns = $this->finder->findInstanceOf($ast, Node\Expr\Assign::class);

        foreach ($assigns as $assign) {
            $target = $this->dimPath($assign->var);

            if ($target === null || $target['path'] === []) {
                continue;
            }

            $root = $target['root'];
            $built[$root] ??= ['index' => null, 'id' => null, 'body' => [], 'origin' => '$'.$root];

            $first = strtolower($target['path'][0]);

            if ($first === 'index' && count($target['path']) === 1) {
                $built[$root]['index'] = $assign->expr;
            } elseif (in_array($first, self::ID_KEYS, true) && count($target['path']) === 1) {
                $built[$root]['id'] = $assign->expr;
            } elseif ($first === 'body' && count($target['path']) > 1) {
                $built[$root]['body'][] = $target['path'][1];
            }
        }

        $requests = [];

        foreach ($built as $request) {
            // Both halves, or it is not an addressable document.
            if ($request['id'] !== null && $request['index'] !== null) {
                $requests[] = $request;
            }
        }

        return $requests;
    }

    /**
     * Walks `$params['body']['username']` back to its root variable.
     *
     * @return array{root: string, path: list<string>}|null
     */
    private function dimPath(Node $node): ?array
    {
        $path = [];

        while ($node instanceof Node\Expr\ArrayDimFetch) {
            if (! $node->dim instanceof Node\Scalar\String_) {
                return null;
            }

            array_unshift($path, $node->dim->value);
            $node = $node->var;
        }

        if (! $node instanceof Node\Expr\Variable || ! is_string($node->name)) {
            return null;
        }

        return ['root' => $node->name, 'path' => $path];
    }

    /** @param array<Node> $ast */
    private function isWriteArgument(Node\Expr\Array_ $array, array $ast): bool
    {
        /** @var list<Node\Expr\MethodCall> $calls */
        $calls = $this->finder->findInstanceOf($ast, Node\Expr\MethodCall::class);

        foreach ($calls as $call) {
            if (! $call->name instanceof Node\Identifier
                || ! in_array($call->name->toString(), self::WRITE_METHODS, true)) {
                continue;
            }

            foreach ($call->getArgs() as $arg) {
                if ($arg->value === $array) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<string> */
    private function literalKeys(Node\Expr\Array_ $array): array
    {
        $keys = [];

        foreach ($array->items as $item) {
            if ($item !== null && $item->key instanceof Node\Scalar\String_) {
                $keys[] = $item->key->value;
            }
        }

        return $keys;
    }

    /**
     * @param  array{index: Node, id: Node, body: list<string>, origin: string}  $request
     */
    private function build(array $request, SubjectReference $matcher, string $store, string $file): ?FlowFinding
    {
        $id = KeyPattern::from($request['id']);

        if ($id === null || $id['refs'] === []) {
            // A literal document id cannot be keyed on a person.
            return null;
        }

        $match = $matcher->match($id['refs']);

        if ($match === null) {
            return null;
        }

        $index = KeyPattern::from($request['index'])['pattern'] ?? '{?}';

        // An index name is a single segment, so a slash inside one would make
        // the target parse as a document id that is not there.
        $index = str_replace('/', '_', $index);

        $evidence = [
            sprintf('%s in %s carries an index and a document id', $request['origin'], $file),
            "document id is {$match['matched']}",
        ];

        if ($request['body'] !== []) {
            sort($request['body']);
            $evidence[] = 'document body carries '.implode(', ', array_slice($request['body'], 0, 8));
        }

        $evidence[] = 'inferred by static analysis, verify before relying on it';

        return new FlowFinding(
            kind: LocationKind::SearchIndex,
            store: $store,
            pattern: $index.'/{id}',
            confidence: $match['confidence'],
            evidence: $evidence,
        );
    }

    private function namesAnEngine(string $code): bool
    {
        $lower = strtolower($code);

        foreach (self::ENGINE_WORDS as $word) {
            if (str_contains($lower, $word)) {
                return true;
            }
        }

        return false;
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
