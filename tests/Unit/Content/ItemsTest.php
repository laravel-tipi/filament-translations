<?php

declare(strict_types=1);

use Tipi\Translations\Filament\Content\Alignment;
use Tipi\Translations\Filament\Content\Formats\Markdown;
use Tipi\Translations\Filament\Content\Nodes\DocumentNode;
use Tipi\Translations\Filament\Content\Nodes\ItemsNode;
use Tipi\Translations\Filament\Content\Nodes\Node;
use Tipi\Translations\Filament\Content\Nodes\ObjectNode;
use Tipi\Translations\Filament\Content\Nodes\TextNode;

function features(?string $idKey = 'id'): ItemsNode
{
    return new ItemsNode(new ObjectNode([
        'title' => new TextNode(false),
        'body' => new DocumentNode(new Markdown),
    ], ['title' => 'Title', 'body' => 'Body']), idKey: $idKey);
}

function align(Node $node, mixed $source, mixed $target): Alignment
{
    $alignment = new Alignment;
    $node->align('', $source, $target, $alignment);

    return $alignment;
}

$source = [
    ['id' => 'a', 'title' => 'Dry', 'body' => 'Very **dry**.', 'icon' => 'sun.svg'],
    ['id' => 'b', 'title' => 'Red', 'body' => null, 'icon' => 'grape.svg'],
];

it('translates the text fields of items and keeps their other data', function () use ($source): void {
    $node = features();

    $segments = $node->segments('features', '', [], $source);

    expect(array_map(fn ($segment) => [$segment->path, $segment->context, $segment->text], $segments))->toBe([
        ['a/title', ['1', 'Title'], 'Dry'],
        ['a/body/0', ['1', 'Body'], 'Very <b1>dry</b1>.'],
        ['b/title', ['2', 'Title'], 'Red'],
    ]);

    expect($node->rebuild('', $source, ['a/title' => 'მშრალი', 'a/body/0' => 'ძალიან <b1>მშრალი</b1>.']))->toBe([
        ['id' => 'a', 'title' => 'მშრალი', 'body' => "ძალიან **მშრალი**.\n", 'icon' => 'sun.svg'],
        // Untranslated texts are empty, never the source's.
        ['id' => 'b', 'title' => null, 'body' => null, 'icon' => 'grape.svg'],
    ]);
});

it('follows items by id when the source adds, removes or moves them', function () use ($source): void {
    $node = features();
    $translation = $node->rebuild('', $source, ['a/title' => 'მშრალი', 'b/title' => 'წითელი']);

    $changed = [
        ['id' => 'c', 'title' => 'New', 'icon' => 'new.svg'],
        ['id' => 'b', 'title' => 'Red', 'body' => null, 'icon' => 'grape.svg'],
    ];

    $alignment = align($node, $changed, $translation);

    expect($alignment->texts)->toBe(['b/title' => 'წითელი'])
        // The removed item's translation is kept for reference only.
        ->and($alignment->orphans)->toBe(['მშრალი']);

    expect($node->rebuild('', $changed, $alignment->texts))->toBe([
        ['id' => 'c', 'title' => null, 'icon' => 'new.svg'],
        ['id' => 'b', 'title' => 'წითელი', 'body' => null, 'icon' => 'grape.svg'],
    ]);
});

it('matches items without ids by position only while their data is unchanged', function () use ($source): void {
    $node = features(idKey: null);
    $withoutIds = array_map(fn (array $item): array => array_diff_key($item, ['id' => true]), $source);
    $translation = $node->rebuild('', $withoutIds, ['#1/title' => 'მშრალი', '#2/title' => 'წითელი']);

    expect(align($node, $withoutIds, $translation)->texts)->toBe(['#1/title' => 'მშრალი', '#2/title' => 'წითელი']);

    // Swapped in the source: their icons tell them apart, so nothing is misassigned.
    $alignment = align($node, array_reverse($withoutIds), $translation);

    expect($alignment->texts)->toBe([])
        ->and($alignment->orphans)->toBe(['მშრალი', 'წითელი']);
});

it('translates builder blocks by type and keeps undeclared blocks whole', function (): void {
    $node = new ItemsNode(blocks: [
        'heading' => new ObjectNode(['text' => new TextNode(false)]),
        'quote' => new ObjectNode(['text' => new TextNode, 'author' => new TextNode(false)]),
    ], blockLabels: ['heading' => 'Heading', 'quote' => 'Quote']);

    $source = [
        ['type' => 'heading', 'data' => ['id' => 'h', 'text' => 'Saperavi', 'level' => 'h2']],
        ['type' => 'image', 'data' => ['id' => 'i', 'url' => 'wine.jpg']],
        ['type' => 'quote', 'data' => ['id' => 'q', 'text' => 'In vino veritas', 'author' => 'Pliny']],
    ];

    expect(array_map(fn ($segment) => [$segment->path, $segment->context], $node->segments('content', '', [], $source)))->toBe([
        ['h/text', ['1 · Heading', 'Text']],
        ['q/text', ['3 · Quote', 'Text']],
        ['q/author', ['3 · Quote', 'Author']],
    ]);

    $translation = $node->rebuild('', $source, ['h/text' => 'საფერავი', 'q/text' => 'ღვინოში ჭეშმარიტებაა']);

    expect($translation)->toBe([
        ['type' => 'heading', 'data' => ['id' => 'h', 'text' => 'საფერავი', 'level' => 'h2']],
        ['type' => 'image', 'data' => ['id' => 'i', 'url' => 'wine.jpg']],
        ['type' => 'quote', 'data' => ['id' => 'q', 'text' => 'ღვინოში ჭეშმარიტებაა', 'author' => null]],
    ]);

    // The block type changed for the same id: its translation no longer applies.
    $source[0]['type'] = 'quote';
    $source[0]['data'] = ['id' => 'h', 'text' => 'Saperavi', 'author' => null];

    expect(align($node, $source, $translation)->texts)->toBe(['q/text' => 'ღვინოში ჭეშმარიტებაა']);
});

it('translates simple repeaters item by item', function (): void {
    $node = new ItemsNode(new TextNode(false), idKey: null);

    expect($node->rebuild('', ['Dry', 'Red'], ['#2' => 'წითელი']))->toBe([null, 'წითელი']);
});
