<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Content;

/**
 * Inline content as translators edit it: text with numbered tags.
 *
 *     This is <b1>excellent</b1> wine, see <a2>the map</a2>.<br>
 *     Run <code3/> first.
 *
 * Pairs mark formatting and links, voids stand for content kept from the
 * source (images, code, merge tags). Translators may move tags and nest
 * them differently, but must keep each one exactly once. A line break is
 * a new line. Anything else that looks like a tag is an error, so a typo
 * can't silently end up as text.
 *
 * Trees are lists of strings and Tags.
 *
 * @internal
 */
final class Inline
{
    public const string TAG = '/<(\/?)([a-z]+[1-9][0-9]*)(\/?)>/';

    /**
     * @param  list<string|Tag>  $nodes
     */
    public static function render(array $nodes): string
    {
        $text = '';

        foreach ($nodes as $node) {
            if (is_string($node)) {
                $text .= $node;
            } elseif ($node->void) {
                $text .= "<{$node->name}/>";
            } else {
                $text .= "<{$node->name}>".self::render($node->children)."</{$node->name}>";
            }
        }

        return $text;
    }

    /**
     * Read a translation, checking it against the source's tags.
     *
     * @param  array<string, bool>  $tags  name => whether it is void
     * @return list<string|Tag>
     *
     * @throws InvalidTranslation
     */
    public static function parse(string $text, array $tags): array
    {
        $tokens = preg_split(self::TAG, $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        /** @var list<array{0: string, 1: list<string|Tag>}> $stack */
        $stack = [['', []]];
        $used = [];

        for ($i = 0, $count = count($tokens); $i < $count; $i += 4) {
            if ($tokens[$i] !== '') {
                $stack[array_key_last($stack)][1][] = $tokens[$i];
            }

            if ($i + 3 >= $count) {
                break;
            }

            [$closing, $name, $selfClosing] = [$tokens[$i + 1] === '/', $tokens[$i + 2], $tokens[$i + 3] === '/'];
            $shown = '<'.($closing ? '/' : '').$name.($selfClosing ? '/' : '').'>';

            if (! array_key_exists($name, $tags)) {
                throw new InvalidTranslation('unknown_tag', ['tag' => $shown]);
            }

            if ($tags[$name] !== $selfClosing || ($closing && $selfClosing)) {
                throw new InvalidTranslation('tag_form', ['tag' => $shown, 'expected' => $tags[$name] ? "<{$name}/>" : "<{$name}>…</{$name}>"]);
            }

            if ($closing) {
                $open = array_pop($stack);

                if (count($stack) === 0 || $open[0] !== $name) {
                    throw new InvalidTranslation('tag_order', ['tag' => $shown]);
                }

                if (! self::hasText($open[1])) {
                    throw new InvalidTranslation('tag_empty', ['tag' => "<{$name}>"]);
                }

                $stack[array_key_last($stack)][1][] = new Tag($name, $open[1]);

                continue;
            }

            if (isset($used[$name])) {
                throw new InvalidTranslation('tag_twice', ['tag' => $tags[$name] ? "<{$name}/>" : "<{$name}>"]);
            }

            $used[$name] = true;

            if ($selfClosing) {
                $stack[array_key_last($stack)][1][] = new Tag($name, void: true);
            } else {
                $stack[] = [$name, []];
            }
        }

        if (count($stack) > 1) {
            throw new InvalidTranslation('tag_unclosed', ['tag' => '<'.$stack[array_key_last($stack)][0].'>']);
        }

        $missing = array_diff(array_keys($tags), array_keys($used));

        if ($missing !== []) {
            throw new InvalidTranslation('tag_missing', ['tags' => implode(', ', array_map(
                fn (string $name): string => $tags[$name] ? "<{$name}/>" : "<{$name}>…</{$name}>",
                $missing,
            ))]);
        }

        return self::canonical($stack[0][1]);
    }

    /**
     * The canonical form of a tree, so two texts that read the same compare
     * equal: whitespace runs are one space, no space around line breaks or
     * at the ends, and pairs don't start or end with whitespace (it moves
     * outside them).
     *
     * @param  list<string|Tag>  $nodes
     * @return list<string|Tag>
     */
    public static function canonical(array $nodes): array
    {
        $nodes = self::collapse($nodes);

        return self::trim($nodes);
    }

    /**
     * Whether the tree has text outside void tags.
     *
     * @param  list<string|Tag>  $nodes
     */
    public static function hasText(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if (is_string($node) ? trim($node, " \n\t\r\u{00A0}") !== '' : (! $node->void && self::hasText($node->children))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The text without tags, for placeholder checks.
     *
     * @param  list<string|Tag>  $nodes
     */
    public static function plain(array $nodes): string
    {
        $text = '';

        foreach ($nodes as $node) {
            $text .= is_string($node) ? $node : ($node->void ? ' ' : self::plain($node->children));
        }

        return $text;
    }

    /**
     * The void tags of a tree, in order.
     *
     * @param  list<string|Tag>  $nodes
     * @return list<Tag>
     */
    public static function voids(array $nodes): array
    {
        $voids = [];

        foreach ($nodes as $node) {
            if ($node instanceof Tag) {
                array_push($voids, ...($node->void ? [$node] : self::voids($node->children)));
            }
        }

        return $voids;
    }

    /**
     * @param  list<string|Tag>  $nodes
     * @return list<string|Tag>
     */
    private static function collapse(array $nodes): array
    {
        $result = [];

        foreach ($nodes as $node) {
            if (is_string($node)) {
                self::append($result, $node);

                continue;
            }

            if ($node->void) {
                $result[] = $node;

                continue;
            }

            $children = self::collapse($node->children);

            // Spaces at the edges of a pair belong outside it.
            $before = self::edge($children, true, ' ');
            $after = self::edge($children, false, ' ');

            if ($before) {
                self::append($result, ' ');
            }

            $result[] = $node->with($children);

            if ($after) {
                self::append($result, ' ');
            }
        }

        return $result;
    }

    /**
     * @param  list<string|Tag>  $nodes
     */
    private static function append(array &$nodes, string $text): void
    {
        $last = array_key_last($nodes);

        if ($last !== null && is_string($nodes[$last])) {
            $text = $nodes[$last].$text;
            array_pop($nodes);
        }

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/[ \t\f\x0B]+/u', ' ', $text);
        $text = (string) preg_replace('/ ?\n ?/', "\n", $text);

        if ($text !== '') {
            $nodes[] = $text;
        }
    }

    /**
     * Cut the given characters off one edge of a tree; whether any were.
     *
     * @param  list<string|Tag>  $nodes
     */
    private static function edge(array &$nodes, bool $start, string $characters): bool
    {
        $index = $start ? array_key_first($nodes) : array_key_last($nodes);

        if ($index === null) {
            return false;
        }

        $node = $nodes[$index];

        if ($node instanceof Tag) {
            if ($node->void) {
                return false;
            }

            $children = $node->children;
            $cut = self::edge($children, $start, $characters);
            $nodes[$index] = $node->with($children);

            return $cut;
        }

        $trimmed = $start ? ltrim($node, $characters) : rtrim($node, $characters);

        if ($trimmed !== '') {
            $nodes[$index] = $trimmed;

            return $trimmed !== $node;
        }

        array_splice($nodes, $index, 1);
        self::edge($nodes, $start, $characters);

        return true;
    }

    /**
     * @param  list<string|Tag>  $nodes
     * @return list<string|Tag>
     */
    private static function trim(array $nodes): array
    {
        self::edge($nodes, true, " \n");
        self::edge($nodes, false, " \n");

        return $nodes;
    }
}
