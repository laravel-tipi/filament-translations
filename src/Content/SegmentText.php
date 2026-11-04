<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Content;

use Tipi\Translations\Filament\Content\Nodes\TextNode;

/**
 * Reads what a translator typed for a segment: checks it and returns it
 * in canonical form, or null when it is blank (not translated).
 *
 * Checked here, for the form and again when saving:
 * - tags: each of the source's, exactly once, well nested (rich text);
 * - placeholders: the source's, as often as in the source;
 * - line breaks: none in single-line texts;
 * - length: the field's minimum and maximum (plain text).
 *
 * @internal
 */
final class SegmentText
{
    /**
     * Placeholders that are never translated: {{ name }}, {name},
     * :name and printf's %s / %1$d.
     */
    public const array PLACEHOLDERS = [
        '/\{\{\s*[^{}]+?\s*\}\}/u',
        '/\{[A-Za-z_][A-Za-z0-9_.]*\}/u',
        '/(?<![\p{L}\p{N}_:]):[A-Za-z_][A-Za-z0-9_]*/u',
        '/%(?:\d+\$)?[sdf]/u',
    ];

    /**
     * @param  list<string>  $placeholders  regular expressions
     *
     * @throws InvalidTranslation
     */
    public static function read(Segment $segment, mixed $text, array $placeholders = self::PLACEHOLDERS): ?string
    {
        if (! is_string($text) || trim($text) === '') {
            return null;
        }

        if ($segment->rich) {
            $tree = Inline::parse(str_replace(["\r\n", "\r"], "\n", $text), $segment->tagForms());

            if (! Inline::hasText($tree)) {
                return null;
            }

            $canonical = Inline::render($tree);
            $plain = Inline::plain($tree);
            $source = Inline::plain(Inline::parse($segment->text, $segment->tagForms()));
        } else {
            $canonical = (string) TextNode::normalize($text);
            $plain = $canonical;
            $source = $segment->text;
        }

        if (! $segment->multiline && str_contains($canonical, "\n")) {
            throw new InvalidTranslation('single_line');
        }

        if (! $segment->rich) {
            $length = mb_strlen($canonical);

            if (isset($segment->length['max']) && $length > $segment->length['max']) {
                throw new InvalidTranslation('too_long', ['max' => (string) $segment->length['max']]);
            }

            if (isset($segment->length['min']) && $length < $segment->length['min']) {
                throw new InvalidTranslation('too_short', ['min' => (string) $segment->length['min']]);
            }
        }

        self::checkPlaceholders($source, $plain, $placeholders);

        return $canonical;
    }

    /**
     * @param  list<string>  $patterns
     *
     * @throws InvalidTranslation
     */
    private static function checkPlaceholders(string $source, string $translation, array $patterns): void
    {
        if ($patterns === []) {
            return;
        }

        $expected = self::placeholders($source, $patterns);
        $actual = self::placeholders($translation, $patterns);

        $missing = self::difference($expected, $actual);

        if ($missing !== []) {
            throw new InvalidTranslation('placeholder_missing', ['placeholders' => implode(', ', $missing)]);
        }

        $unexpected = self::difference($actual, $expected);

        if ($unexpected !== []) {
            throw new InvalidTranslation('placeholder_unexpected', ['placeholders' => implode(', ', $unexpected)]);
        }
    }

    /**
     * @param  list<string>  $patterns
     * @return list<string>
     */
    private static function placeholders(string $text, array $patterns): array
    {
        // One pass over all patterns, so overlapping ones match once.
        $alternatives = array_map(
            fn (string $pattern): string => '(?:'.substr($pattern, 1, strrpos($pattern, $pattern[0]) - 1).')',
            $patterns,
        );

        preg_match_all('/'.implode('|', $alternatives).'/u', $text, $matches);

        return array_map(fn (string $match): string => (string) preg_replace('/\s+/u', '', $match), $matches[0]);
    }

    /**
     * Items of $a not matched by an item of $b, counting repeats.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<string>
     */
    private static function difference(array $a, array $b): array
    {
        foreach ($b as $item) {
            $index = array_search($item, $a, true);

            if ($index !== false) {
                unset($a[$index]);
            }
        }

        return array_values(array_unique($a));
    }
}
