<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Content\Formats;

use Dom\Comment;
use Dom\Element;
use Dom\HTMLDocument as DomDocument;
use Dom\Node;
use Dom\Text;
use Illuminate\Support\Str;
use Tipi\Translations\Filament\Content\Document;
use Tipi\Translations\Filament\Content\Inline;
use Tipi\Translations\Filament\Content\Run;
use Tipi\Translations\Filament\Content\Tag;
use Tipi\Translations\Filament\Content\TagInfo;
use Tipi\Translations\Filament\Content\UnsupportedContent;

/**
 * A parsed HTML document. Reading and rebuilding walk the tree with the
 * same code, so paths and tag names are the same in both.
 *
 * Paths count block children only, so they don't move when the text
 * around blocks changes or disappears. A block's runs are its inline
 * content before, between and after its block children ("slots").
 *
 * @internal
 */
final class HtmlDocument implements Document
{
    /**
     * Inline elements whose text is translated, by tag kind.
     */
    private const array PAIRS = [
        'strong' => 'b', 'b' => 'b', 'em' => 'i', 'i' => 'i', 'u' => 'u', 'ins' => 'u',
        's' => 's', 'del' => 's', 'strike' => 's', 'a' => 'a',
        'sub' => 'sub', 'sup' => 'sup', 'mark' => 'mark', 'span' => 'span',
        'small' => 'span', 'big' => 'span', 'font' => 'span', 'abbr' => 'span',
        'cite' => 'span', 'q' => 'span', 'dfn' => 'span', 'time' => 'span',
        'data' => 'span', 'bdi' => 'span', 'bdo' => 'span', 'label' => 'span',
    ];

    /**
     * Inline content kept whole: never translated, moved as a void tag.
     */
    private const array VOIDS = [
        'code' => 'code', 'kbd' => 'code', 'samp' => 'code', 'var' => 'code', 'tt' => 'code',
        'img' => 'img', 'picture' => 'img', 'svg' => 'media', 'math' => 'media',
        'video' => 'media', 'audio' => 'media', 'iframe' => 'media', 'object' => 'media',
        'embed' => 'media', 'canvas' => 'media', 'input' => 'input', 'select' => 'input',
        'textarea' => 'input', 'button' => 'input', 'wbr' => 'html', 'script' => 'html',
        'style' => 'html', 'template' => 'html', 'noscript' => 'html',
    ];

    /**
     * Blocks kept whole, never translated.
     */
    private const array OPAQUE = ['pre', 'hr', 'head', 'meta', 'link'];

    /**
     * Marks elements with texts in attributes (alt, title) and custom
     * blocks, numbered in document order, while reading and rebuilding.
     */
    private const string MARK = 'data-tipi-text';

    /**
     * @var array<string, Run>
     */
    private array $runs = [];

    private string $skeleton;

    private bool $hasContent = false;

    /**
     * @param  array<string, list<string>>  $customBlocks  custom block id => its translatable config keys
     *
     * @throws UnsupportedContent
     */
    public function __construct(
        private readonly string $html,
        private readonly array $customBlocks = [],
    ) {
        [$body] = self::parse($html);

        $marks = $this->mark($body);
        $placed = [];

        // Texts in attributes come right after the text around them.
        $place = function (array $nodes) use ($marks, &$placed): void {
            foreach (self::marksWithin($nodes) as $index) {
                if (! isset($placed[$index])) {
                    $placed[$index] = true;
                    $this->runs += $marks[$index];
                }
            }
        };

        $structure = $this->walk($body, '', function (array $nodes, string $path, Element $block) use ($place): array {
            $tags = [];
            $elements = [];
            $counter = 0;
            $tree = Inline::canonical($this->tree($nodes, $tags, $elements, $counter));

            $run = new Run($path, self::role($block), $tree, $tags);
            $this->runs[$path] = $run;
            $place($nodes);
            $this->hasContent = $this->hasContent || $run->hasText() || Inline::voids($tree) !== [];

            $voids = array_map(fn (Tag $tag): string => $tags[$tag->name]->signature, Inline::voids($tree));
            sort($voids);

            return $voids;
        }, $place);

        foreach ($marks as $index => $runs) {
            if (! isset($placed[$index])) {
                $this->runs += $runs;
            }
        }

        $this->hasContent = $this->hasContent || array_filter($marks) !== [];
        $this->skeleton = md5(json_encode($structure, JSON_THROW_ON_ERROR));
    }

    public function isEmpty(): bool
    {
        return ! $this->hasContent;
    }

    public function runs(): array
    {
        return $this->runs;
    }

    public function skeleton(): string
    {
        return $this->skeleton;
    }

    public function rebuild(array $trees): mixed
    {
        [$body, $document] = self::parse($this->html);

        $this->mark($body);

        $this->walk($body, '', function (array $nodes, string $path) use ($trees, $document): array {
            $tags = [];
            $elements = [];
            $counter = 0;
            $this->tree($nodes, $tags, $elements, $counter);

            $run = $this->runs[$path];

            if (! $run->hasText() || $nodes === []) {
                return [];
            }

            $tree = $trees[$path] ?? null;
            $tree = $tree === null || $tree === [] ? Inline::voids($run->nodes) : $tree;

            $parent = $nodes[0]->parentNode;
            $reference = $nodes[array_key_last($nodes)]->nextSibling;

            foreach ($nodes as $node) {
                $node->remove();
            }

            foreach (self::build($tree, $elements, $document) as $new) {
                $parent?->insertBefore($new, $reference);
            }

            return [];
        }, fn () => null);

        // Marked elements, wherever their tags moved: their texts.
        foreach (iterator_to_array($body->querySelectorAll('['.self::MARK.']')) as $element) {
            $index = $element->getAttribute(self::MARK);
            $text = fn (string $path): ?string => isset($trees[$path]) ? trim(Inline::plain($trees[$path])) : null;

            if (isset($this->runs['alt'.$index])) {
                $element->setAttribute('alt', $text('alt'.$index) ?? '');
            }

            if (isset($this->runs['title'.$index])) {
                $element->setAttribute('title', $text('title'.$index) ?? '');
            }

            if (self::isCustomBlock($element)) {
                $config = self::config($element);

                foreach ($this->runs as $path => $run) {
                    if (str_starts_with((string) $path, "block{$index}.")) {
                        data_set($config, substr((string) $path, strlen("block{$index}.")), $text((string) $path));
                    }
                }

                $element->setAttribute('data-config', json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            }

            $element->removeAttribute(self::MARK);
        }

        return $body->innerHTML;
    }

    /**
     * Number the elements with texts in attributes and the custom blocks,
     * in document order, and read their texts: index => runs.
     *
     * @return array<int, array<string, Run>>
     *
     * @throws UnsupportedContent
     */
    private function mark(Element $body): array
    {
        $marks = [];
        $index = 0;

        foreach (iterator_to_array($body->getElementsByTagName('*')) as $element) {
            if (self::insideOpaque($element) || (self::isOpaque($element) && ! self::isCustomBlock($element))) {
                continue;
            }

            $runs = [];

            if ($element->localName === 'img' && ($alt = self::text($element->getAttribute('alt'))) !== null) {
                $runs['alt'.$index] = new Run('alt'.$index, 'alt', [$alt], multiline: false);
            }

            if (($title = self::text($element->getAttribute('title'))) !== null) {
                $runs['title'.$index] = new Run('title'.$index, 'title', [$title], multiline: false);
            }

            if (self::isCustomBlock($element)) {
                $runs += $this->blockRuns($element, $index);
            }

            // Every image and titled element is numbered, with text or not,
            // so numbers stay the same when translations leave texts empty.
            if ($element->localName === 'img' || $element->hasAttribute('title') || self::isCustomBlock($element)) {
                $element->setAttribute(self::MARK, (string) $index);
                $marks[$index++] = $runs;
            }
        }

        return $marks;
    }

    /**
     * The texts of a custom block's config, as declared for its id. A
     * block with text whose keys aren't declared can't be translated.
     *
     * @return array<string, Run>
     *
     * @throws UnsupportedContent
     */
    private function blockRuns(Element $block, int $index): array
    {
        $id = (string) $block->getAttribute('data-id');
        $config = self::config($block);
        $keys = $this->customBlocks[$id] ?? null;

        if ($keys === null) {
            if (self::hasStrings($config)) {
                throw new UnsupportedContent('custom_block', ['block' => $id]);
            }

            return [];
        }

        $label = (string) ($block->getAttribute('data-label') ?: Str::headline($id));
        $runs = [];

        foreach ($keys as $key) {
            $value = data_get($config, $key);

            if (is_string($value) && ($text = self::text($value)) !== null) {
                $path = "block{$index}.{$key}";
                $runs[$path] = new Run($path, 'block_field', [$text], label: $label.' › '.Str::headline(str_replace('.', ' ', $key)));
            }
        }

        return $runs;
    }

    /**
     * @return array<mixed>
     */
    private static function config(Element $block): array
    {
        $config = json_decode((string) $block->getAttribute('data-config'), true);

        return is_array($config) ? $config : [];
    }

    private static function hasStrings(mixed $value): bool
    {
        if (is_string($value)) {
            return trim($value) !== '';
        }

        return is_array($value) && in_array(true, array_map(self::hasStrings(...), $value), true);
    }

    private static function text(?string $value): ?string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return $value === '' ? null : $value;
    }

    /**
     * The marks of nodes and their descendants.
     *
     * @param  list<Node>  $nodes
     * @return list<int>
     */
    private static function marksWithin(array $nodes): array
    {
        $marks = [];

        foreach ($nodes as $node) {
            if (! $node instanceof Element) {
                continue;
            }

            foreach ([$node, ...iterator_to_array($node->querySelectorAll('['.self::MARK.']'))] as $element) {
                if ($element->hasAttribute(self::MARK)) {
                    $marks[] = (int) $element->getAttribute(self::MARK);
                }
            }
        }

        return $marks;
    }

    private static function insideOpaque(Element $element): bool
    {
        for ($parent = $element->parentElement; $parent !== null && $parent->localName !== 'body'; $parent = $parent->parentElement) {
            if (self::isOpaque($parent)) {
                return true;
            }
        }

        return false;
    }

    private static function isCustomBlock(Element $element): bool
    {
        return $element->getAttribute('data-type') === 'customBlock';
    }

    /**
     * @return array{0: Element, 1: DomDocument}
     */
    private static function parse(string $html): array
    {
        $document = DomDocument::createFromString(
            '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>',
            LIBXML_NOERROR,
        );

        /** @var Element $body */
        $body = $document->body;

        return [$body, $document];
    }

    /**
     * Walk a block, handing each run to $visit, and return the block's
     * structure: what $visit returns for each slot, and each block child.
     *
     * @param  callable(list<Node>, string, Element): list<string>  $visit
     * @param  callable(list<Node>): void  $visitOpaque
     * @return list<mixed>
     */
    private function walk(Element $block, string $path, callable $visit, callable $visitOpaque): array
    {
        $structure = [];
        $slot = 0;
        $index = 0;
        $run = [];

        $flush = function () use (&$run, &$slot, &$structure, $path, $block, $visit): void {
            $structure[] = $visit($run, $path.'~'.$slot++, $block);
            $run = [];
        };

        foreach (iterator_to_array($block->childNodes) as $child) {
            if (! $child instanceof Element || ! self::isBlock($child)) {
                if ($child instanceof Text || $child instanceof Element || $child instanceof Comment) {
                    $run[] = $child;
                }

                continue;
            }

            $flush();

            $childPath = ltrim($path.'.'.$index++, '.');

            if (self::isOpaque($child)) {
                $this->hasContent = true;
                $visitOpaque([$child]);
                $structure[] = ['opaque', md5($this->withoutTexts($child))];
            } else {
                $structure[] = [$child->localName, self::attributes($child), $this->walk($child, $childPath, $visit, $visitOpaque)];
            }
        }

        $flush();

        return $structure;
    }

    /**
     * Read inline nodes into a tree, naming tags in reading order and
     * collecting the element of each tag.
     *
     * @param  iterable<Node>  $nodes
     * @param  array<string, TagInfo>  $tags
     * @param  array<string, Node>  $elements
     * @return list<string|Tag>
     */
    private function tree(iterable $nodes, array &$tags, array &$elements, int &$counter): array
    {
        $tree = [];

        foreach ($nodes as $node) {
            if ($node instanceof Text) {
                $tree[] = (string) preg_replace('/\s+/u', ' ', $node->data);

                continue;
            }

            if ($node instanceof Element && $node->localName === 'br') {
                $tree[] = "\n";

                continue;
            }

            if ($node instanceof Element && self::isPair($node)) {
                $state = [$tags, $elements, $counter];
                $name = self::PAIRS[$node->localName].++$counter;
                $tags[$name] = new TagInfo(self::PAIRS[$node->localName], false, $this->signature($node), self::describe($node));
                $elements[$name] = $node;

                $children = $this->tree($node->childNodes, $tags, $elements, $counter);

                if (Inline::hasText($children)) {
                    $tree[] = new Tag($name, $children);

                    continue;
                }

                // Formatting without text (a linked image): kept whole.
                [$tags, $elements, $counter] = $state;
            }

            if ($node instanceof Element || $node instanceof Comment) {
                $kind = $node instanceof Element ? (self::VOIDS[$node->localName] ?? self::PAIRS[$node->localName] ?? 'html') : 'html';
                $name = $kind.++$counter;
                $tags[$name] = new TagInfo($kind, true, $node instanceof Element ? $this->signature($node) : 'html '.md5($node->data), $node instanceof Element ? self::describe($node) : TagInfo::describe('html'));
                $elements[$name] = $node;
                $tree[] = new Tag($name, void: true);
            }
        }

        return $tree;
    }

    /**
     * @param  list<string|Tag>  $tree
     * @param  array<string, Node>  $elements
     * @return list<Node>
     */
    private static function build(array $tree, array $elements, DomDocument $document): array
    {
        $nodes = [];

        foreach ($tree as $node) {
            if (is_string($node)) {
                foreach (explode("\n", $node) as $index => $line) {
                    if ($index > 0) {
                        $nodes[] = $document->createElement('br');
                    }

                    if ($line !== '') {
                        $nodes[] = $document->createTextNode($line);
                    }
                }

                continue;
            }

            $source = $elements[$node->name];

            if ($node->void) {
                $nodes[] = $source->cloneNode(true);

                continue;
            }

            $element = $source->cloneNode(false);

            foreach (self::build($node->children, $elements, $document) as $child) {
                $element->appendChild($child);
            }

            $nodes[] = $element;
        }

        return $nodes;
    }

    private static function isBlock(Element $element): bool
    {
        $name = $element->localName;

        if (self::isOpaque($element)) {
            return true;
        }

        if ($name === 'br' || isset(self::VOIDS[$name]) || $element->hasAttribute('data-type') && $name === 'span') {
            return false;
        }

        if (! isset(self::PAIRS[$name])) {
            return true;
        }

        // An inline element around blocks is a block itself.
        foreach ($element->children as $child) {
            if (self::isBlock($child)) {
                return true;
            }
        }

        return false;
    }

    private static function isOpaque(Element $element): bool
    {
        return in_array($element->localName, self::OPAQUE, true)
            || $element->getAttribute('data-type') === 'customBlock';
    }

    private static function isPair(Element $element): bool
    {
        return isset(self::PAIRS[$element->localName]) && ! $element->hasAttribute('data-type');
    }

    /**
     * What a run is, by its block, or for a plain paragraph by the list
     * item, quote or cell it sits in (rich editors wrap their text in <p>).
     */
    private static function role(Element $block): string
    {
        if ($block->localName === 'p' || $block->localName === 'div') {
            for ($parent = $block->parentElement; $parent !== null && $parent->localName !== 'body'; $parent = $parent->parentElement) {
                $role = self::blockRole($parent);

                if ($role !== 'paragraph') {
                    return $role;
                }
            }
        }

        return self::blockRole($block);
    }

    private static function blockRole(Element $block): string
    {
        return match ($block->localName) {
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => 'heading',
            'li', 'dt', 'dd' => 'list_item',
            'td', 'th' => 'table_cell',
            'blockquote' => 'quote',
            'figcaption', 'caption', 'summary', 'legend' => 'caption',
            default => 'paragraph',
        };
    }

    /**
     * Identifies a tag across documents. Pairs: element and attributes.
     * Voids: their markup, without alt texts.
     */
    private function signature(Element $element): string
    {
        if (self::isPair($element)) {
            return $element->localName.' '.json_encode(self::attributes($element), JSON_THROW_ON_ERROR);
        }

        return md5($this->withoutTexts($element));
    }

    private static function describe(Element $element): string
    {
        $kind = self::isPair($element) ? self::PAIRS[$element->localName] : (self::VOIDS[$element->localName] ?? 'html');

        if ($element->hasAttribute('data-type')) {
            return TagInfo::describe('variable', (string) ($element->getAttribute('data-label') ?: $element->getAttribute('data-id') ?: $element->textContent));
        }

        return TagInfo::describe($kind, match ($kind) {
            'a' => (string) $element->getAttribute('href'),
            'img' => (string) ($element->getAttribute('alt') ?: $element->getAttribute('src')),
            'code' => (string) $element->textContent,
            default => '',
        });
    }

    /**
     * @return array<string, string>
     */
    private static function attributes(Element $element): array
    {
        $attributes = [];

        foreach ($element->attributes as $attribute) {
            $attributes[$attribute->name] = $attribute->value;
        }

        // Translated or ours.
        unset($attributes[self::MARK], $attributes['title']);
        ksort($attributes);

        return $attributes;
    }

    /**
     * Markup without the texts that are translated: alt and title texts,
     * and the declared texts of custom blocks.
     */
    private function withoutTexts(Element $element): string
    {
        $copy = $element->cloneNode(true);

        if (! $copy instanceof Element) {
            return '';
        }

        foreach ([$copy, ...iterator_to_array($copy->querySelectorAll('*'))] as $descendant) {
            $descendant->removeAttribute('alt');
            $descendant->removeAttribute('title');
            $descendant->removeAttribute(self::MARK);

            if (self::isCustomBlock($descendant)) {
                $config = self::config($descendant);

                foreach ($this->customBlocks[(string) $descendant->getAttribute('data-id')] ?? [] as $key) {
                    data_forget($config, $key);
                }

                $descendant->setAttribute('data-config', json_encode($config, JSON_THROW_ON_ERROR));
            }
        }

        return $copy->outerHTML;
    }
}
