<?php

declare(strict_types=1);

namespace PrivacyCI\Discovery\Flow;

use PhpParser\Node;

/**
 * Turns a key or path expression into a reviewable pattern.
 *
 *     "user:{$userId}"                 => user:{userId}
 *     'avatars/' . $user->id . '.jpg'  => avatars/{user.id}.jpg
 *     "cache:" . $this->key()          => cache:{?}
 *
 * The braces are not cosmetic. A developer reading `user:{userId}` in a report
 * can tell instantly whether it names a person; `user:*` cannot be judged at all.
 */
final class KeyPattern
{
    /**
     * @return array{pattern: string, refs: list<string>}|null
     *         `refs` holds the interpolated expressions, rendered for matching.
     */
    public static function from(?Node $node): ?array
    {
        if ($node === null) {
            return null;
        }

        $refs = [];
        $pattern = self::render($node, $refs);

        return $pattern === null ? null : ['pattern' => $pattern, 'refs' => $refs];
    }

    /** @param list<string> $refs */
    private static function render(Node $node, array &$refs): ?string
    {
        if ($node instanceof Node\Scalar\String_) {
            return $node->value;
        }

        if ($node instanceof Node\Scalar\InterpolatedString) {
            $out = '';

            foreach ($node->parts as $part) {
                if ($part instanceof Node\InterpolatedStringPart) {
                    $out .= $part->value;

                    continue;
                }

                $out .= self::placeholder($part, $refs);
            }

            return $out;
        }

        if ($node instanceof Node\Expr\BinaryOp\Concat) {
            $left = self::render($node->left, $refs);
            $right = self::render($node->right, $refs);

            if ($left === null || $right === null) {
                return null;
            }

            return $left.$right;
        }

        // A bare expression as the whole key: sprintf(), a method call or a
        // variable. We cannot name it, but we should not pretend it is absent.
        return self::placeholder($node, $refs);
    }

    /** @param list<string> $refs */
    private static function placeholder(Node $node, array &$refs): string
    {
        $name = self::describe($node);

        if ($name === null) {
            return '{?}';
        }

        $refs[] = $name;

        return '{'.$name.'}';
    }

    /** Renders an expression the way a developer would write it. */
    public static function describe(Node $node): ?string
    {
        if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
            return $node->name;
        }

        if ($node instanceof Node\Expr\PropertyFetch && $node->name instanceof Node\Identifier) {
            $base = self::describe($node->var);

            return $base === null ? null : $base.'.'.$node->name->toString();
        }

        if ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier) {
            $base = self::describe($node->var);

            return $base === null ? null : $base.'.'.$node->name->toString().'()';
        }

        if ($node instanceof Node\Expr\ArrayDimFetch) {
            $base = self::describe($node->var);
            $dim = $node->dim instanceof Node\Scalar\String_ ? $node->dim->value : '?';

            return $base === null ? null : $base.'['.$dim.']';
        }

        return null;
    }
}
