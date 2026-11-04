<?php

declare(strict_types=1);

use League\CommonMark\GithubFlavoredMarkdownConverter;
use Tipi\Translations\Filament\Content\Alignment;
use Tipi\Translations\Filament\Content\Formats\Markdown;
use Tipi\Translations\Filament\Content\InvalidTranslation;
use Tipi\Translations\Filament\Content\Nodes\DocumentNode;
use Tipi\Translations\Filament\Content\UnsupportedContent;

function markdown(): DocumentNode
{
    return new DocumentNode(new Markdown);
}

function html(string $markdown): string
{
    return trim((new GithubFlavoredMarkdownConverter)->convert($markdown)->getContent());
}

const WINE_MARKDOWN = <<<'MD'
## Saperavi

This is **excellent** Georgian wine, see [the map](https://example.com/map "Map").
It ages *well*.

> Kept in a `qvevri`.

1. Harvest
2. Press
   - by **hand**

![A vineyard](/vineyard.jpg)

```php
echo 'not translated';
```

| Year | Note |
|:-----|-----:|
| 2019 | Dry  |

- [x] Bottled
MD;

it('splits Markdown into blocks with tags for inline formatting', function (): void {
    expect(texts(markdown(), WINE_MARKDOWN))->toBe([
        '0' => 'Saperavi',
        '1' => 'This is <b1>excellent</b1> Georgian wine, see <a2>the map</a2>. It ages <i3>well</i3>.',
        'title0' => 'Map',
        '2.0' => 'Kept in a <code1/>.',
        '3.0.0' => 'Harvest',
        '3.1.0' => 'Press',
        '3.1.1.0.0' => 'by <b1>hand</b1>',
        // The image's paragraph has no text: only its description.
        'alt1' => 'A vineyard',
        '6.0.0' => 'Year',
        '6.0.1' => 'Note',
        '6.1.0' => '2019',
        '6.1.1' => 'Dry',
        '7.0.0' => 'Bottled',
    ]);
});

it('rebuilds the source structure with the translated text', function (): void {
    $node = markdown();
    $texts = array_map(fn (string $text): string => preg_replace('/\b([A-Za-z]+)\b(?![^<]*>)/', 'ka-$1', $text), texts($node, WINE_MARKDOWN));
    $texts['1'] = 'ეს <a2>რუკაზე</a2> ნაჩვენები <b1>შესანიშნავი</b1> ღვინოა. <i3>კარგად</i3> ძველდება.';
    unset($texts['4']);

    $translated = $node->rebuild('', WINE_MARKDOWN, $texts);

    // Only the text changed: same blocks, URLs, code, table and task list.
    expect($node->skeleton($translated))->toBe($node->skeleton(WINE_MARKDOWN))
        ->and(html($translated))->toContain('<h2>ka-Saperavi</h2>')
        ->toContain('<p>ეს <a href="https://example.com/map" title="ka-Map">რუკაზე</a> ნაჩვენები <strong>შესანიშნავი</strong> ღვინოა. <em>კარგად</em> ძველდება.</p>')
        ->toContain('<code>qvevri</code>')
        ->toContain('<img src="/vineyard.jpg" alt="ka-A ka-vineyard" />')
        ->toContain("<pre><code class=\"language-php\">echo 'not translated';")
        ->toContain('<th align="left">ka-Year</th>')
        ->toContain('<input checked="" disabled="" type="checkbox"> ka-Bottled')
        ->toContain('<li>ka-Press')
        ->toContain('<li>ka-by <strong>ka-hand</strong></li>');

    // Reading it back lines up with the source.
    $alignment = new Alignment;
    $node->align('', WINE_MARKDOWN, $translated, $alignment);

    expect($alignment->texts['1'])->toBe($texts['1'])
        ->and($alignment->orphans)->toBe([]);
});

it('stores text as text, never as Markdown syntax', function (): void {
    $node = markdown();
    $source = "Plain paragraph.\n\nSecond.";
    $hostile = '# *not italic* _nor_ [a link](javascript:alert(1)) <b>raw</b> `code` | ~~x~~ &copy; \\ !';

    $translated = $node->rebuild('', $source, ['0' => $hostile, '1' => "1. not a list\n- nor this\n> nor a quote"]);

    expect(html($translated))->toBe(
        '<p># *not italic* _nor_ [a link](javascript:alert(1)) &lt;b&gt;raw&lt;/b&gt; `code` | ~~x~~ &amp;copy; \\ !</p>'."\n"
        .'<p>1. not a list<br />'."\n".'- nor this<br />'."\n".'&gt; nor a quote</p>',
    );
});

it('keeps an untranslated block in place, empty', function (): void {
    $node = markdown();
    $source = "First.\n\nSecond.\n\n- One\n- Two";

    $partial = $node->rebuild('', $source, ['0' => 'პირველი.', '2.1.0' => 'ორი']);

    expect($node->skeleton($partial))->toBe($node->skeleton($source))
        ->and(texts($node, $partial))->toBe(['0' => 'პირველი.', '2.1.0' => 'ორი']);

    $alignment = new Alignment;
    $node->align('', $source, $partial, $alignment);

    expect($alignment->texts)->toBe(['0' => 'პირველი.', '2.1.0' => 'ორი']);
});

it('stores nothing when nothing is translated', function (): void {
    expect(markdown()->rebuild('', "First.\n\nSecond.", ['0' => ' ']))->toBeNull()
        ->and(markdown()->segments('body', '', [], " \n "))->toBe([]);
});

it('tells when the source structure changed', function (): void {
    $node = markdown();
    $old = "First.\n\nSecond.";
    $translation = $node->rebuild('', $old, ['0' => 'პირველი.', '1' => 'მეორე.']);

    $alignment = new Alignment;
    $node->align('', "Intro.\n\nFirst.\n\nSecond.", $translation, $alignment);

    // A paragraph was added: nothing is assigned by position.
    expect($alignment->texts)->toBe([])
        ->and($alignment->orphans)->toBe(['პირველი.', 'მეორე.']);
});

it('refuses formatting Markdown can’t express instead of changing it', function (): void {
    // Emphasis between letters and punctuation: **"x"** wouldn't be bold.
    expect(fn () => markdown()->rebuild('', 'This is **excellent** wine.', ['0' => 'ა<b1>"შესანიშნავი"</b1>ბ']))
        ->toThrow(InvalidTranslation::class);

    try {
        markdown()->rebuild('', 'This is **excellent** wine.', ['0' => 'ა<b1>"შესანიშნავი"</b1>ბ']);
    } catch (InvalidTranslation $exception) {
        expect($exception->reason)->toBe('format')->and($exception->path)->toBe('0');
    }
});

it('keeps links around images and autolinks whole', function (): void {
    $source = '[![Logo](/logo.png)](https://example.com) Visit <https://example.com>.';

    expect(texts(markdown(), $source))->toBe(['0' => '<a1/> Visit <url3/>.', 'alt1' => 'Logo']);

    $translated = markdown()->rebuild('', $source, ['0' => 'ეწვიეთ <url3/> <a1/>', 'alt1' => 'ლოგო']);

    expect(html($translated))->toBe('<p>ეწვიეთ <a href="https://example.com">https://example.com</a> <a href="https://example.com"><img src="/logo.png" alt="ლოგო" /></a></p>');
});

it('translates link and image titles, never copies the source’s', function (): void {
    $source = 'See [the map](https://example.com/map "Our vineyards") and ![A cellar](/c.jpg "The cellar").';

    expect(texts(markdown(), $source))->toBe([
        '0' => 'See <a1>the map</a1> and <img2/>.',
        'title0' => 'Our vineyards',
        'alt1' => 'A cellar',
        'title1' => 'The cellar',
    ]);

    // Untranslated titles are left out, not stored in English.
    $translated = markdown()->rebuild('', $source, ['0' => 'იხილეთ <a1>რუკა</a1> და <img2/>.', 'alt1' => 'მარანი']);

    expect(html($translated))->toBe('<p>იხილეთ <a href="https://example.com/map">რუკა</a> და <img src="/c.jpg" alt="მარანი" />.</p>');
});

it('translates the text of HTML blocks', function (): void {
    $source = "Intro.\n\n<div class=\"note\"><p>Keep <strong>cool</strong>.</p></div>\n\nOutro.";

    expect(texts(markdown(), $source))->toBe(['0' => 'Intro.', '1/0.0~0' => 'Keep <b1>cool</b1>.', '2' => 'Outro.']);

    $translated = markdown()->rebuild('', $source, ['1/0.0~0' => 'შეინახეთ <b1>გრილად</b1>.']);

    expect(html($translated))->toContain('<div class="note"><p>შეინახეთ <strong>გრილად</strong>.</p></div>')
        ->not->toContain('Keep');
});

it('refuses HTML it can’t translate safely', function (string $source): void {
    expect(fn () => markdown()->segments('body', '', [], $source))->toThrow(UnsupportedContent::class);
})->with([
    // Text in a block whose closing tag is in another block: the parser would complete it.
    'unclosed block with text' => ["<div><p>Kept</p>\n\nParagraph\n\n</div>"],
    'text in inline attributes' => ['A <abbr title="Georgian">KA</abbr> wine.'],
]);

it('never translates code', function (): void {
    expect(texts(markdown(), "Run `make`:\n\n```\nmake install\n```\n\n    indented code"))->toBe(['0' => 'Run <code1/>:']);
});
