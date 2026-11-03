<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Content\Formats;

use Dom\Comment;
use Dom\Element;
use Dom\HTMLDocument as DomDocument;
use Dom\Node;
use Dom\Text;
use Tipi\Translations\Filament\Content\Document;
use Tipi\Translations\Filament\Content\Inline;
use Tipi\Translations\Filament\Content\Run;
use Tipi\Translations\Filament\Content\Tag;
use Tipi\Translations\Filament\Content\TagInfo;

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
     * Marks images while rebuilding, to give them their translated alt.
     */
    private const string IMAGE = 'data-tipi-image';

    /**
     * @var array<string, Run>
     */
    private array $runs = [];

    private string $skeleton;

    private bool $hasContent = false;

    /**
     * Images seen by the walk in progress.
     */
    private int $images = 0;

    /**
     * Whether the walk in progress rebuilds (marks images) or reads.
     */
    private bool $rebuilding = false;

    public function __construct(private readonly string $html)
    {
        [$body] = self::parse($html);

        $structure = $this->walk($body, '', function (array $nodes, string $path, Element $block): array {
            $tags = [];
            $elements = [];
            $counter = 0;
            $alts = [];
            $tree = Inline::canonical($this->tree($nodes, $tags, $elements, $counter, $alts));

            $run = new Run($path, self::role($block), $tree, $tags);
            $this->runs[$path] = $run;
            // Image descriptions come after the text around their images.
            $this->runs += $alts;
            $this->hasContent = $this->hasContent || $run->hasText() || Inline::voids($tree) !== [];

            $voids = array_map(fn (Tag $tag): string => $tags[$tag->name]->signature, Inline::voids($tree));
            sort($voids);

            return $voids;
        });

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

        $this->images = 0;
        $this->rebuilding = true;

        try {
            $this->walk($body, '', function (array $nodes, string $path) use ($trees, $document): array {
                $tags = [];
                $elements = [];
                $counter = 0;
                $alts = [];
                $this->tree($nodes, $tags, $elements, $counter, $alts);

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
            });
        } finally {
            $this->rebuilding = false;
        }

        foreach ($body->querySelectorAll('['.self::IMAGE.']') as $image) {
            $alt = $trees['img'.$image->getAttribute(self::IMAGE)] ?? null;
            $image->setAttribute('alt', $alt === null ? '' : trim(Inline::plain($alt)));
            $image->removeAttribute(self::IMAGE);
        }

        return $body->innerHTML;
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
     * @return list<mixed>
     */
    private function walk(Element $block, string $path, callable $visit): array
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
                $structure[] = ['opaque', md5(self::withoutAlts($child))];
            } else {
                $structure[] = [$child->localName, self::attributes($child), $this->walk($child, $childPath, $visit)];
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
     * @param  array<string, Run>  $alts
     * @return list<string|Tag>
     */
    private function tree(iterable $nodes, array &$tags, array &$elements, int &$counter, array &$alts): array
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
                $state = [$tags, $elements, $counter, $alts, $this->images];
                $name = self::PAIRS[$node->localName].++$counter;
                $tags[$name] = new TagInfo(self::PAIRS[$node->localName], false, self::signature($node), self::describe($node));
                $elements[$name] = $node;

                $children = $this->tree($node->childNodes, $tags, $elements, $counter, $alts);

                if (Inline::hasText($children)) {
                    $tree[] = new Tag($name, $children);

                    continue;
                }

                // Formatting without text (a linked image): kept whole.
                [$tags, $elements, $counter, $alts, $this->images] = $state;
            }

            if ($node instanceof Element || $node instanceof Comment) {
                $kind = $node instanceof Element ? (self::VOIDS[$node->localName] ?? self::PAIRS[$node->localName] ?? 'html') : 'html';
                $name = $kind.++$counter;
                $tags[$name] = new TagInfo($kind, true, $node instanceof Element ? self::signature($node) : 'html '.md5($node->data), $node instanceof Element ? self::describe($node) : TagInfo::describe('html'));
                $elements[$name] = $node;
                $tree[] = new Tag($name, void: true);

                if ($node instanceof Element) {
                    $this->images($node, $alts);
                }
            }
        }

        return $tree;
    }

    /**
     * Number the images in a void tag: read their alt texts, or mark them
     * while rebuilding.
     *
     * @param  array<string, Run>  $alts
     */
    private function images(Element $element, array &$alts): void
    {
        $images = $element->localName === 'img' ? [$element] : iterator_to_array($element->querySelectorAll('img'));

        foreach ($images as $image) {
            $index = $this->images++;

            if ($this->rebuilding) {
                $image->setAttribute(self::IMAGE, (string) $index);

                continue;
            }

            $alt = trim((string) preg_replace('/\s+/u', ' ', (string) $image->getAttribute('alt')));

            if ($alt !== '') {
                $alts['img'.$index] = new Run('img'.$index, 'alt', [$alt], multiline: false);
            }
        }
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

    private static function role(Element $block): string
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
    private static function signature(Element $element): string
    {
        if (self::isPair($element)) {
            return $element->localName.' '.json_encode(self::attributes($element), JSON_THROW_ON_ERROR);
        }

        return md5(self::withoutAlts($element));
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

        unset($attributes[self::IMAGE]);
        ksort($attributes);

        return $attributes;
    }

    /**
     * Markup without alt texts, which are translated.
     */
    private static function withoutAlts(Element $element): string
    {
        $copy = $element->cloneNode(true);

        if (! $copy instanceof Element) {
            return '';
        }

        $images = $copy->localName === 'img' ? [$copy] : iterator_to_array($copy->querySelectorAll('img'));

        foreach ($images as $image) {
            $image->removeAttribute('alt');
            $image->removeAttribute(self::IMAGE);
        }

        return $copy->outerHTML;
    }
}
