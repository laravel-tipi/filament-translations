<?php

declare(strict_types=1);

use Tipi\Translations\Filament\Content\Inline;
use Tipi\Translations\Filament\Content\InvalidTranslation;
use Tipi\Translations\Filament\Content\Tag;

$tags = ['b1' => false, 'a2' => false, 'img3' => true];

it('reads tags in any order and nesting', function () use ($tags): void {
    $tree = Inline::parse('<a2>რუკა</a2>: <b1>შესანიშნავი <img3/></b1>', $tags);

    expect($tree)->toEqual([
        new Tag('a2', ['რუკა']),
        ': ',
        new Tag('b1', ['შესანიშნავი ', new Tag('img3', void: true)]),
    ]);

    expect(Inline::render(Inline::parse('<b1><a2>x</a2></b1> <img3/>', $tags)))->toBe('<b1><a2>x</a2></b1> <img3/>');
});

it('keeps text that only looks like markup', function (): void {
    expect(Inline::parse('a < b, <3 and <b> or <script>alert(1)</script>', []))
        ->toBe(['a < b, <3 and <b> or <script>alert(1)</script>']);
});

it('rejects broken tags with a reason', function (string $text, string $reason) use ($tags): void {
    try {
        Inline::parse($text, $tags);
        $this->fail('No exception');
    } catch (InvalidTranslation $exception) {
        expect($exception->reason)->toBe($reason);
    }
})->with([
    'unknown' => ['<b1>x</b1> <a2>y</a2> <img3/> <i4>z</i4>', 'unknown_tag'],
    'missing' => ['<b1>x</b1> <img3/>', 'tag_missing'],
    'twice' => ['<b1>x</b1> <b1>y</b1> <a2>z</a2> <img3/>', 'tag_twice'],
    'crossing' => ['<b1>x <a2>y</b1> z</a2> <img3/>', 'tag_order'],
    'unclosed' => ['<b1>x <a2>y</a2> <img3/>', 'tag_unclosed'],
    'empty' => ['<b1> </b1> <a2>y</a2> <img3/>', 'tag_empty'],
    'void as pair' => ['<b1>x</b1> <a2>y</a2> <img3>z</img3>', 'tag_form'],
    'pair as void' => ['<b1/> <a2>y</a2> <img3/>', 'tag_form'],
]);

it('compares texts that read the same as equal', function () use ($tags): void {
    $canonical = fn (string $text): string => Inline::render(Inline::parse($text, $tags));

    expect($canonical("  a <b1> bold </b1>  <a2>link</a2>\t<img3/>  "))->toBe('a <b1>bold</b1> <a2>link</a2> <img3/>')
        ->and($canonical("line <b1>one</b1> \n  two <a2>x</a2><img3/>\n"))->toBe("line <b1>one</b1>\ntwo <a2>x</a2><img3/>");
});
