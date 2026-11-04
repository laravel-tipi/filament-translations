<?php

declare(strict_types=1);

use Tipi\Translations\Filament\Content\Alignment;
use Tipi\Translations\Filament\Content\Formats\Html;
use Tipi\Translations\Filament\Content\Formats\RichText;
use Tipi\Translations\Filament\Content\InvalidTranslation;
use Tipi\Translations\Filament\Content\Nodes\DocumentNode;
use Tipi\Translations\Filament\Content\UnsupportedContent;

function richText(): DocumentNode
{
    return new DocumentNode(new Html(['hero' => ['heading']]));
}

// What Filament's RichEditor stores.
const WINE_HTML = '<h2>Saperavi</h2>'
    .'<p style="text-align: center">This is <strong>excellent</strong> wine, see <a href="/map" target="_blank" rel="noopener">the map</a>.<br>Dear <span data-type="mergeTag" data-id="name"></span>, run <code>make</code>.</p>'
    .'<ul><li><p>Dry</p></li><li><p>Red <em>and</em> <s>sweet</s></p><ul><li><p>Nested</p></li></ul></li></ul>'
    .'<p><img src="/vineyard.jpg" alt="A vineyard"></p>'
    .'<div data-type="customBlock" data-config="{&quot;heading&quot;:&quot;Not translated&quot;}" data-id="hero"></div>'
    .'<table><tbody><tr><th><p>Year</p></th></tr><tr><td><p>2019</p></td></tr></tbody></table>'
    .'<blockquote><p>Quoted</p></blockquote>';

it('splits HTML into runs with tags for inline elements', function (): void {
    expect(texts(richText(), WINE_HTML))->toEqual([
        '0~0' => 'Saperavi',
        '1~0' => "This is <b1>excellent</b1> wine, see <a2>the map</a2>.\nDear <span3/>, run <code4/>.",
        '2.0.0~0' => 'Dry',
        '2.1.0~0' => 'Red <i1>and</i1> <s2>sweet</s2>',
        '2.1.1.0.0~0' => 'Nested',
        'alt0' => 'A vineyard',
        'block1.heading' => 'Not translated',
        '5.0.0.0.0~0' => 'Year',
        '5.0.1.0.0~0' => '2019',
        '6.0~0' => 'Quoted',
    ]);
});

it('changes only the text of the source tree', function (): void {
    $node = richText();
    $texts = array_map(fn (string $text): string => 'ქა '.$text, texts($node, WINE_HTML));
    $texts['1~0'] = "ნახეთ <a2>რუკა</a2>: <b1>შესანიშნავი</b1> ღვინო.\nძვირფასო <span3/>, გაუშვით <code4/>.";
    $texts['alt0'] = 'ვენახი';
    $texts['block1.heading'] = 'სათარგმნი';
    unset($texts['2.0.0~0']);

    $translated = $node->rebuild('', WINE_HTML, $texts);

    expect($translated)->toBe(
        '<h2>ქა Saperavi</h2>'
        .'<p style="text-align: center">ნახეთ <a href="/map" target="_blank" rel="noopener">რუკა</a>: <strong>შესანიშნავი</strong> ღვინო.<br>ძვირფასო <span data-type="mergeTag" data-id="name"></span>, გაუშვით <code>make</code>.</p>'
        // Untranslated: the block stays, empty.
        .'<ul><li><p></p></li><li><p>ქა Red <em>and</em> <s>sweet</s></p><ul><li><p>ქა Nested</p></li></ul></li></ul>'
        .'<p><img src="/vineyard.jpg" alt="ვენახი"></p>'
        .'<div data-type="customBlock" data-config="{&quot;heading&quot;:&quot;სათარგმნი&quot;}" data-id="hero"></div>'
        .'<table><tbody><tr><th><p>ქა Year</p></th></tr><tr><td><p>ქა 2019</p></td></tr></tbody></table>'
        .'<blockquote><p>ქა Quoted</p></blockquote>',
    );

    $alignment = new Alignment;
    $node->align('', WINE_HTML, $translated, $alignment);

    expect($alignment->texts)->toEqual($texts)->and($alignment->orphans)->toBe([]);
});

it('inserts typed markup as text', function (): void {
    $translated = richText()->rebuild('', '<p>Hello</p>', ['0~0' => '<img src=x onerror=alert(1)> & <b>bold</b>']);

    expect($translated)->toBe('<p>&lt;img src=x onerror=alert(1)&gt; &amp; &lt;b&gt;bold&lt;/b&gt;</p>');
});

it('lines up translations whose links changed in the source', function (): void {
    $node = richText();
    $translated = $node->rebuild('', '<p>See <a href="/old">the map</a>.</p>', ['0~0' => 'ნახეთ <a1>რუკა</a1>.']);

    $alignment = new Alignment;
    $node->align('', '<p>See <a href="/new">the map</a>.</p>', $translated, $alignment);

    expect($alignment->texts)->toBe(['0~0' => 'ნახეთ <a1>რუკა</a1>.']);

    // Rebuilt from the new source, the translation links to the new page.
    expect($node->rebuild('', '<p>See <a href="/new">the map</a>.</p>', $alignment->texts))->toBe('<p>ნახეთ <a href="/new">რუკა</a>.</p>');
});

it('tells when the source structure changed', function (): void {
    $node = richText();
    $translated = $node->rebuild('', '<p>One</p><p>Two</p>', ['0~0' => 'ერთი', '1~0' => 'ორი']);

    $alignment = new Alignment;
    $node->align('', '<p>One</p><h2>New</h2><p>Two</p>', $translated, $alignment);

    expect($alignment->texts)->toBe([])->and($alignment->orphans)->toBe(['ერთი', 'ორი']);
});

it('treats documents without text as empty', function (): void {
    expect(richText()->segments('body', '', [], '<p></p><p> </p>'))->toBe([])
        ->and(richText()->rebuild('', '<p>One</p>', []))->toBeNull();
});

it('checks tags against the source run', function (): void {
    expect(fn () => richText()->rebuild('', '<p>A <strong>b</strong></p>', ['0~0' => 'ა ბ']))
        ->toThrow(InvalidTranslation::class);
});

it('translates rich text stored as TipTap JSON, and keeps it JSON', function (): void {
    $node = new DocumentNode(new RichText);

    $source = ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Very '],
            ['type' => 'text', 'text' => 'dry', 'marks' => [['type' => 'bold']]],
            ['type' => 'text', 'text' => ' red.'],
        ]],
    ]];

    expect(texts($node, $source))->toBe(['0~0' => 'Very <b1>dry</b1> red.']);

    $translated = $node->rebuild('', $source, ['0~0' => '<b1>მშრალი</b1> წითელი.']);

    expect($translated)->toBeArray()
        ->and($translated['type'])->toBe('doc')
        ->and($translated['content'][0]['content'][0])->toMatchArray(['type' => 'text', 'text' => 'მშრალი', 'marks' => [['type' => 'bold']]])
        ->and(texts($node, $translated))->toBe(['0~0' => '<b1>მშრალი</b1> წითელი.']);
});

it('translates title attributes and leaves untranslated ones empty', function (): void {
    $node = richText();
    $source = '<p title="Note">A <abbr title="Georgian">KA</abbr> wine, <a href="/x" title="More">more</a>.</p>';

    expect(texts($node, $source))->toBe([
        '0~0' => 'A <span1>KA</span1> wine, <a2>more</a2>.',
        'title1' => 'Georgian',
        'title2' => 'More',
        'title0' => 'Note',
    ]);

    expect($node->rebuild('', $source, ['0~0' => 'ქართული <span1>KA</span1> ღვინო, <a2>მეტი</a2>.', 'title1' => 'ქართული']))
        // Untranslated titles are empty, never the source's.
        ->toBe('<p title="">ქართული <abbr title="ქართული">KA</abbr> ღვინო, <a href="/x" title="">მეტი</a>.</p>');
});

it('refuses custom blocks with text that isn’t declared', function (): void {
    $source = '<p>Hi</p><div data-type="customBlock" data-id="cta" data-config="{&quot;label&quot;:&quot;Buy now&quot;}"></div>';

    expect(fn () => (new DocumentNode(new Html))->segments('body', '', [], $source))
        ->toThrow(UnsupportedContent::class);

    // Declared without texts: kept as it is.
    expect(texts(new DocumentNode(new Html(['cta' => []])), $source))->toBe(['0~0' => 'Hi']);
});

it('keeps custom blocks without text and code whole', function (): void {
    $source = '<div data-type="customBlock" data-id="divider" data-config="{&quot;size&quot;:2}"></div><pre><code>echo 1;</code></pre><p>Text</p>';

    expect(texts(new DocumentNode(new Html), $source))->toBe(['2~0' => 'Text']);
});
