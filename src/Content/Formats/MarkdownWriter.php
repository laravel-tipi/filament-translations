<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Content\Formats;

use League\CommonMark\Node\Inline\Text;
use Tipi\Translations\Filament\Content\Inline;
use Tipi\Translations\Filament\Content\Run;
use Tipi\Translations\Filament\Content\Tag;

/**
 * @internal
 */
final class MarkdownWriter
{
    /**
     * @param  array<string, Run>  $runs
     * @param  array<string, array<string, array<string, mixed>>>  $specs
     * @param  array<string, list<string|Tag>|null>  $trees
     */
    public function __construct(
        private readonly array $runs,
        private readonly array $specs,
        private readonly array $trees,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    public function blocks(array $blocks, bool $tight = false): string
    {
        return implode($tight ? "\n" : "\n\n", array_map($this->block(...), $blocks));
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function block(array $block): string
    {
        return match ($block['type']) {
            'paragraph' => ($block['task'] === null ? '' : ($block['task'] ? '[x] ' : '[ ] ')).$this->run($block['run'], Markdown::EMPTY),
            'heading' => str_repeat('#', $block['level']).' '.$this->run($block['run'], Markdown::EMPTY),
            'quote' => self::prefix($this->blocks($block['children']), '> ', '>'),
            'list' => $this->list($block),
            'code' => self::code($block['literal'], $block['info']),
            'html' => $this->html($block),
            'hr' => '***',
            'table' => $this->table($block),
        };
    }

    /**
     * @param  array<string, mixed>  $list
     */
    private function list(array $list): string
    {
        $items = [];

        foreach ($list['items'] as $index => $blocks) {
            $marker = $list['ordered'] ? ($list['start'] + $index).$list['delimiter'] : $list['bullet'];
            $body = $this->blocks($blocks, $list['tight']);

            $items[] = $body === '' ? $marker : $marker.' '.self::prefix($body, str_repeat(' ', strlen($marker) + 1), '', skipFirst: true);
        }

        return implode($list['tight'] ? "\n" : "\n\n", $items);
    }

    /**
     * @param  array<string, mixed>  $table
     */
    private function table(array $table): string
    {
        $row = fn (array $cells): string => '| '.implode(' | ', array_map(fn (string $path): string => $this->run($path, ''), $cells)).' |';

        $delimiter = '| '.implode(' | ', array_map(fn (?string $align): string => match ($align) {
            'left' => ':---',
            'right' => '---:',
            'center' => ':---:',
            default => '---',
        }, $table['align'])).' |';

        return implode("\n", [$row($table['head']), $delimiter, ...array_map($row, $table['rows'])]);
    }

    /**
     * The inline Markdown of a run: its translation, or for a run without
     * one, the source's void tags (or $empty without any).
     */
    private function run(string $path, string $empty): string
    {
        $run = $this->runs[$path];
        $tree = $run->hasText() ? ($this->trees[$path] ?? null) : $run->nodes;

        if ($tree === null || $tree === []) {
            $tree = Inline::voids($run->nodes);
            $tree = array_merge(...array_map(fn (Tag $tag): array => [$tag, ' '], $tree));
            array_pop($tree);
        }

        $lineStart = true;
        $markdown = $this->inline($tree, $this->specs[$path] ?? [], $lineStart);

        return $markdown === '' ? $empty : $markdown;
    }

    /**
     * @param  list<string|Tag>  $nodes
     * @param  array<string, array<string, mixed>>  $specs
     */
    private function inline(array $nodes, array $specs, bool &$lineStart): string
    {
        $markdown = '';

        foreach ($nodes as $node) {
            if (is_string($node)) {
                $markdown .= $this->text($node, $lineStart);

                continue;
            }

            $spec = $specs[$node->name];
            $lineStart = false;

            $markdown .= match ($spec['kind']) {
                'i' => '*'.$this->inline($node->children, $specs, $lineStart).'*',
                'b' => '**'.$this->inline($node->children, $specs, $lineStart).'**',
                's' => '~~'.$this->inline($node->children, $specs, $lineStart).'~~',
                'a' => '['.$this->inline($node->void ? $spec['children'] : $node->children, $specs, $lineStart).']('.self::destination($spec['url'], $this->attributeText('title', $spec['index'])).')',
                'url' => '<'.$spec['text'].'>',
                'img' => '!['.self::escape($this->attributeText('alt', $spec['index'])).']('.self::destination($spec['url'], $this->attributeText('title', $spec['index'])).')',
                'code' => self::codeSpan($spec['literal']),
                'html' => $spec['literal'],
            };
        }

        return $markdown;
    }

    /**
     * The translated alt or title text of an image or link, '' without one.
     */
    private function attributeText(string $kind, int $index): string
    {
        $tree = $this->trees[$kind.$index] ?? null;

        return $tree === null ? '' : Inline::plain($tree);
    }

    /**
     * An HTML block: rebuilt by the HTML reader with its translated runs.
     *
     * @param  array<string, mixed>  $block
     */
    private function html(array $block): string
    {
        if ($block['document'] === null) {
            return $block['literal'];
        }

        $trees = [];

        foreach (array_keys($block['document']->runs()) as $runPath) {
            $tree = $this->trees[$block['path'].'/'.$runPath] ?? null;

            if ($tree !== null) {
                $trees[$runPath] = $tree;
            }
        }

        return trim((string) $block['document']->rebuild($trees));
    }

    /**
     * Escape text so it can't be read as Markdown syntax.
     */
    private function text(string $text, bool &$lineStart): string
    {
        $lines = explode("\n", $text);

        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $lineStart = true;
            }

            $escaped = self::escape($line);

            if ($lineStart && $escaped !== '') {
                // Text at the start of a line can't start a block.
                $escaped = (string) preg_replace(['/^([-+=])/', '/^(\d+)([.)])/'], ['\\\\$1', '$1\\\\$2'], $escaped);
                $lineStart = false;
            }

            $lines[$index] = $escaped;
        }

        return implode("\\\n", $lines);
    }

    private static function escape(string $text): string
    {
        return (string) preg_replace('/([\\\\`*_\[\]<>&~|#!])/', '\\\\$1', $text);
    }

    private static function destination(string $url, string $title): string
    {
        $destination = preg_match('/[\s()<>]/', $url) === 1 || $url === ''
            ? '<'.str_replace(['<', '>'], ['%3C', '%3E'], $url).'>'
            : $url;

        return $title === '' ? $destination : $destination.' "'.str_replace(['\\', '"'], ['\\\\', '\\"'], $title).'"';
    }

    private static function codeSpan(string $literal): string
    {
        preg_match_all('/`+/', $literal, $runs);
        $length = 1;

        while (in_array(str_repeat('`', $length), $runs[0], true)) {
            $length++;
        }

        $fence = str_repeat('`', $length);
        $padded = str_starts_with($literal, '`') || str_ends_with($literal, '`') || (str_starts_with($literal, ' ') && str_ends_with($literal, ' ') && trim($literal) !== '');

        return $padded ? "{$fence} {$literal} {$fence}" : $fence.$literal.$fence;
    }

    private static function code(string $literal, string $info): string
    {
        $character = str_contains($info, '`') ? '~' : '`';
        preg_match_all('/^ {0,3}('.preg_quote($character, '/').'+)/m', $literal, $fences);
        $length = max(3, ...array_map(fn (string $fence): int => strlen($fence) + 1, $fences[1] ?: ['']));
        $fence = str_repeat($character, $length);

        return $fence.$info."\n".$literal.(str_ends_with($literal, "\n") || $literal === '' ? '' : "\n").$fence;
    }

    private static function prefix(string $text, string $prefix, string $emptyPrefix, bool $skipFirst = false): string
    {
        $lines = explode("\n", $text);

        foreach ($lines as $index => $line) {
            if ($skipFirst && $index === 0) {
                continue;
            }

            $lines[$index] = $line === '' ? $emptyPrefix : $prefix.$line;
        }

        return implode("\n", $lines);
    }
}
