<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Content\Formats;

use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\Extension\Strikethrough\Strikethrough;
use League\CommonMark\Extension\Table\Table;
use League\CommonMark\Extension\Table\TableCell;
use League\CommonMark\Extension\Table\TableRow;
use League\CommonMark\Extension\Table\TableSection;
use League\CommonMark\Extension\TaskList\TaskListItemMarker;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use Tipi\Translations\Filament\Content\Inline;
use Tipi\Translations\Filament\Content\Run;
use Tipi\Translations\Filament\Content\Tag;
use Tipi\Translations\Filament\Content\TagInfo;
use Tipi\Translations\Filament\Content\UnsupportedContent;

/**
 * @internal
 */
final class MarkdownReader
{
    /**
     * @var array<string, Run>
     */
    public array $runs = [];

    /**
     * Writing details of each run's tags: path => name => spec.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    public array $specs = [];

    private int $images = 0;

    /**
     * @param  iterable<Node>  $nodes
     * @return list<array<string, mixed>>
     */
    public function blocks(iterable $nodes, string $path, string $role = 'paragraph'): array
    {
        $blocks = [];
        $index = 0;

        foreach ($nodes as $node) {
            $at = ltrim($path.'.'.$index++, '.');

            $blocks[] = match (true) {
                $node instanceof Paragraph => $this->paragraph($node, $at, $role),
                $node instanceof Heading => ['type' => 'heading', 'level' => $node->getLevel(), 'run' => $this->run($node->children(), $at, 'heading', false)],
                $node instanceof BlockQuote => ['type' => 'quote', 'children' => $this->blocks($node->children(), $at, 'quote')],
                $node instanceof ListBlock => $this->list($node, $at),
                $node instanceof FencedCode => ['type' => 'code', 'literal' => $node->getLiteral(), 'info' => (string) $node->getInfo()],
                $node instanceof IndentedCode => ['type' => 'code', 'literal' => $node->getLiteral(), 'info' => ''],
                $node instanceof HtmlBlock => ['type' => 'html', 'literal' => rtrim($node->getLiteral(), "\n")],
                $node instanceof ThematicBreak => ['type' => 'hr'],
                $node instanceof Table => $this->table($node, $at),
                default => throw new UnsupportedContent('Markdown block ['.$node::class.'] is not supported.'),
            };
        }

        return $blocks;
    }

    /**
     * @return array<string, mixed>
     */
    private function paragraph(Paragraph $paragraph, string $path, string $role): array
    {
        $children = iterator_to_array($paragraph->children(), false);
        $task = null;

        if (($children[0] ?? null) instanceof TaskListItemMarker) {
            $task = $children[0]->isChecked();
            array_shift($children);
        }

        return ['type' => 'paragraph', 'task' => $task, 'run' => $this->run($children, $path, $role)];
    }

    /**
     * @return array<string, mixed>
     */
    private function list(ListBlock $list, string $path): array
    {
        $data = $list->getListData();
        $items = [];
        $index = 0;

        foreach ($list->children() as $item) {
            if (! $item instanceof ListItem) {
                throw new UnsupportedContent('Markdown list children must be list items.');
            }

            $items[] = $this->blocks($item->children(), $path.'.'.$index++, 'list_item');
        }

        return [
            'type' => 'list',
            'ordered' => $data->type === ListBlock::TYPE_ORDERED,
            'start' => $data->start ?? 1,
            'delimiter' => $data->delimiter === ListBlock::DELIM_PAREN ? ')' : '.',
            'bullet' => $data->bulletChar ?? '-',
            'tight' => $list->isTight(),
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function table(Table $table, string $path): array
    {
        $head = [];
        $rows = [];
        $align = [];
        $row = 0;

        foreach ($table->children() as $section) {
            if (! $section instanceof TableSection) {
                continue;
            }

            foreach ($section->children() as $tableRow) {
                if (! $tableRow instanceof TableRow) {
                    continue;
                }

                $cells = [];
                $column = 0;

                foreach ($tableRow->children() as $cell) {
                    if (! $cell instanceof TableCell) {
                        continue;
                    }

                    if ($section->isHead()) {
                        $align[] = $cell->getAlign();
                    }

                    $cells[] = $this->run($cell->children(), "{$path}.{$row}.{$column}", 'table_cell', false);
                    $column++;
                }

                $row++;

                if ($section->isHead()) {
                    $head = $cells;
                } else {
                    $rows[] = $cells;
                }
            }
        }

        return ['type' => 'table', 'align' => $align, 'head' => $head, 'rows' => $rows];
    }

    /**
     * Read inline content into a run; returns its path.
     *
     * @param  iterable<Node>  $nodes
     */
    private function run(iterable $nodes, string $path, string $role, bool $multiline = true): string
    {
        $tags = [];
        $specs = [];
        $counter = 0;

        $before = $this->runs;
        $tree = $this->inline($nodes, $path, $tags, $specs, $counter);

        // Image descriptions read inside the run come after it.
        $alts = array_diff_key($this->runs, $before);
        $this->runs = $before;

        // An untranslated block holds a zero-width space: no text.
        if (trim(str_replace("\u{200B}", '', Inline::plain($tree))) === '') {
            $tree = array_values(array_filter($tree, fn (string|Tag $node): bool => $node instanceof Tag));
        }

        $this->runs[$path] = new Run($path, $role, Inline::canonical($tree), $tags, $multiline);
        $this->runs += $alts;
        $this->specs[$path] = $specs;

        return $path;
    }

    /**
     * @param  iterable<Node>  $nodes
     * @param  array<string, TagInfo>  $tags
     * @param  array<string, array<string, mixed>>  $specs
     * @return list<string|Tag>
     */
    private function inline(iterable $nodes, string $path, array &$tags, array &$specs, int &$counter): array
    {
        $tree = [];

        foreach ($nodes as $node) {
            if ($node instanceof Text) {
                $tree[] = $node->getLiteral();

                continue;
            }

            if ($node instanceof Newline) {
                $tree[] = $node->getType() === Newline::HARDBREAK ? "\n" : ' ';

                continue;
            }

            $pair = match (true) {
                $node instanceof Strong => ['b', 'b', []],
                $node instanceof Emphasis => ['i', 'i', []],
                $node instanceof Strikethrough => ['s', 's', []],
                default => null,
            };

            if ($node instanceof Link) {
                $text = self::plainText($node);
                $url = $node->getUrl();

                if (iterator_count($node->children()) === 1 && $node->firstChild() instanceof Text && ($text === $url || 'mailto:'.$text === $url)) {
                    $tree[] = $this->void('url', $url, ['url' => $url, 'text' => $text], $tags, $specs, $counter);

                    continue;
                }

                $pair = ['a', 'a '.$url, ['url' => $url, 'title' => (string) $node->getTitle()]];
            }

            if ($pair !== null) {
                [$kind, $signature, $spec] = $pair;
                $name = $kind.++$counter;
                $children = $this->inline($node->children(), $path, $tags, $specs, $counter);

                // Formatting without text can't be translated: keep it whole.
                if (! Inline::hasText($children)) {
                    // Tags inside are written with it, not by the translator.
                    foreach (self::names($children) as $inner) {
                        unset($tags[$inner]);
                    }

                    $tags[$name] = new TagInfo($kind, true, $signature, TagInfo::describe($kind, $spec['url'] ?? ''));
                    $specs[$name] = ['kind' => $kind, ...$spec, 'children' => $children];
                    $tree[] = new Tag($name, void: true);

                    continue;
                }

                $tags[$name] = new TagInfo($kind, false, $signature, TagInfo::describe($kind, $spec['url'] ?? ''));
                $specs[$name] = ['kind' => $kind, ...$spec];
                $tree[] = new Tag($name, $children);

                continue;
            }

            $tree[] = match (true) {
                $node instanceof Image => $this->image($node, $path, $tags, $specs, $counter),
                $node instanceof Code => $this->void('code', $node->getLiteral(), ['literal' => $node->getLiteral()], $tags, $specs, $counter),
                $node instanceof HtmlInline => $this->void('html', $node->getLiteral(), ['literal' => $node->getLiteral()], $tags, $specs, $counter),
                default => throw new UnsupportedContent('Markdown inline ['.$node::class.'] is not supported.'),
            };
        }

        return $tree;
    }

    /**
     * @param  array<string, TagInfo>  $tags
     * @param  array<string, array<string, mixed>>  $specs
     */
    private function image(Image $image, string $path, array &$tags, array &$specs, int &$counter): Tag
    {
        $alt = trim(self::plainText($image));
        $index = $this->images++;

        if ($alt !== '') {
            $this->runs['img'.$index] = new Run('img'.$index, 'alt', [$alt], multiline: false);
            $this->specs['img'.$index] = [];
        }

        return $this->void('img', $image->getUrl(), ['url' => $image->getUrl(), 'title' => (string) $image->getTitle(), 'index' => $index], $tags, $specs, $counter, $alt === '' ? $image->getUrl() : $alt);
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, TagInfo>  $tags
     * @param  array<string, array<string, mixed>>  $specs
     */
    private function void(string $kind, string $signature, array $spec, array &$tags, array &$specs, int &$counter, ?string $detail = null): Tag
    {
        $name = $kind.++$counter;
        $tags[$name] = new TagInfo($kind, true, $kind.' '.$signature, TagInfo::describe($kind, $detail ?? $signature));
        $specs[$name] = ['kind' => $kind, ...$spec];

        return new Tag($name, void: true);
    }

    /**
     * @param  list<string|Tag>  $nodes
     * @return list<string>
     */
    private static function names(array $nodes): array
    {
        $names = [];

        foreach ($nodes as $node) {
            if ($node instanceof Tag) {
                $names = [...$names, $node->name, ...self::names($node->children)];
            }
        }

        return $names;
    }

    private static function plainText(Node $node): string
    {
        $text = '';

        foreach ($node->children() as $child) {
            $text .= match (true) {
                $child instanceof Text, $child instanceof Code => $child->getLiteral(),
                $child instanceof Newline => ' ',
                default => self::plainText($child),
            };
        }

        return $text;
    }
}
