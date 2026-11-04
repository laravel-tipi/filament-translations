<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Grape;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Region;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Wine;
use Tipi\Translations\Filament\TranslationEditor;

/*
 * Translators edit texts; the editor keeps the source's structure. A
 * complete translation is published; a partial one is an unpublished
 * draft, so readers never see gaps. Every test checks what readers get.
 */

beforeEach(function (): void {
    $this->signIn();
    configureEditor(fn (TranslationEditor $e) => $e->page()->comparison());
});

/**
 * What a reader of the locale gets: the published translation, or the
 * fallback.
 */
function readerSees(Model $record, string $attribute, string $locale = 'ka'): mixed
{
    return $record->fresh()->getTranslation($attribute, $locale, fallback: true);
}

function markdownHtml(?string $markdown): string
{
    return (string) (new GithubFlavoredMarkdownConverter)->convert((string) $markdown)->getContent();
}

const TASTING = "## Nose\n\nThis is **excellent** wine, see [the map](https://example.com/map).\n\n- Cherry\n- Plum\n\n```\nnot translated\n```";

it('publishes a complete Markdown translation, with movable formatting', function (): void {
    $wine = makeRecord(Wine::class, ['en' => ['tasting' => TASTING]]);

    $editor = openEditor('page', $wine, 'ka');

    expect($editor->html())
        ->toContain('This is <span class="fi-tipi-tr-tag" title="Bold">&lt;b1&gt;</span>excellent')
        ->toContain('title="Link: https://example.com/map"');

    $editor->fill('ka', [
        'tasting/0' => 'არომატი',
        // Georgian word order: the link comes first.
        'tasting/1' => '<a2>რუკაზე</a2> ნაჩვენები <b1>შესანიშნავი</b1> ღვინოა.',
        'tasting/2.0.0' => 'ალუბალი',
        'tasting/2.1.0' => 'ქლიავი',
    ])->save()->assertSaved();

    expect(markdownHtml(readerSees($wine, 'tasting')))
        ->toContain('<h2>არომატი</h2>')
        ->toContain('<p><a href="https://example.com/map">რუკაზე</a> ნაჩვენები <strong>შესანიშნავი</strong> ღვინოა.</p>')
        ->toContain("<li>ალუბალი</li>\n<li>ქლიავი</li>")
        ->toContain("<pre><code>not translated\n</code></pre>")
        ->not->toContain('Cherry');

    expect(openEditor('page', $wine->fresh(), 'ka')->text('ka', 'tasting/1'))->toBe('<a2>რუკაზე</a2> ნაჩვენები <b1>შესანიშნავი</b1> ღვინოა.');
});

it('keeps a partial translation as a draft: readers never see gaps', function (): void {
    $wine = makeRecord(Wine::class, ['en' => ['tasting' => TASTING]]);

    openEditor('page', $wine, 'ka')
        ->fill('ka', ['tasting/0' => 'არომატი', 'tasting/2.0.0' => 'ალუბალი'])
        ->save()
        ->assertSaved();

    $fresh = $wine->fresh();

    // Readers get the whole English text, not Georgian with holes.
    expect($fresh->getTranslation('tasting', 'ka'))->toBeNull()
        ->and(readerSees($wine, 'tasting'))->toBe(TASTING)
        // The draft holds exactly what was translated, in the source's structure.
        ->and($fresh->getTranslationDraft('tasting', 'ka'))->toContain('## არომატი')->not->toContain('Cherry');

    // The editor continues from the draft, and says it isn't published.
    $editor = openEditor('page', $fresh, 'ka');

    expect($editor->text('ka', 'tasting/0'))->toBe('არომატი')
        ->and($editor->text('ka', 'tasting/1'))->toBeNull()
        ->and($editor->html())->toContain(__('tipi-filament-translations::editor.attribute.draft_fallback', ['locale' => 'English']));

    // Completing it publishes it and drops the draft.
    $editor->fill('ka', [
        'tasting/1' => '<a2>რუკა</a2>: <b1>შესანიშნავი</b1> ღვინო.',
        'tasting/2.1.0' => 'ქლიავი',
    ])->save()->assertSaved();

    expect(markdownHtml(readerSees($wine, 'tasting')))->toContain('<h2>არომატი</h2>')->not->toContain('Nose')
        ->and($wine->fresh()->hasTranslationDraft('tasting', 'ka'))->toBeFalse();
});

it('keeps the published translation public while its update is a draft', function (): void {
    $grape = makeRecord(Grape::class, ['en' => ['description' => "First.\n\nSecond."]]);

    openEditor('page', $grape, 'ka')->fill('ka', ['description/0' => 'პირველი.', 'description/1' => 'მეორე.'])->save()->assertSaved();

    // The author adds a paragraph; the translator starts updating.
    $grape = $grape->fresh();
    $grape->setTranslation('description', 'en', "First.\n\nSecond.\n\nThird.")->save();

    openEditor('page', $grape->fresh(), 'ka')->fill('ka', ['description/0' => 'პირველი!'])->save()->assertSaved();

    // Readers keep the complete, older translation until the update is done.
    expect(readerSees($grape, 'description'))->toBe("პირველი.\n\nმეორე.\n")
        ->and($grape->fresh()->getTranslationDraft('description', 'ka'))->toContain('პირველი\\!');
});

it('translates rich text without letting translators change its markup', function (): void {
    $wine = makeRecord(Wine::class, ['en' => ['description' => '<p class="lead">Dear <span data-type="mergeTag" data-id="name"></span>, try <a href="/shop" target="_blank" title="Our shop">our <em>best</em> wine</a>.</p><p><img src="/v.jpg" alt="A vineyard"></p>']]);

    $editor = openEditor('page', $wine, 'ka')
        ->fill('ka', [
            'description/0~0' => '<script>alert(1)</script> ძვირფასო <span1/>, სცადეთ <a2>ჩვენი <i3>საუკეთესო</i3> ღვინო</a2>.',
            'description/title0' => 'ჩვენი მაღაზია',
            'description/alt1' => 'ვენახი',
        ])
        ->save()
        ->assertSaved();

    expect(readerSees($wine, 'description'))->toBe(
        '<p class="lead">&lt;script&gt;alert(1)&lt;/script&gt; ძვირფასო <span data-type="mergeTag" data-id="name"></span>, სცადეთ <a href="/shop" target="_blank" title="ჩვენი მაღაზია">ჩვენი <em>საუკეთესო</em> ღვინო</a>.</p><p><img src="/v.jpg" alt="ვენახი"></p>',
    );

    // A missing tag is refused, with a reason, and nothing changes.
    $editor->fill('ka', ['description/0~0' => 'ძვირფასო, სცადეთ ღვინო.'])->save();

    $editor->assertErrors(['ka' => ['description/0~0']]);
    expect($editor->livewire->errors()->first('data.'.$editor::path('ka', 'description/0~0')))->toContain('<span1/>');
});

it('translates the declared texts of custom blocks, and refuses undeclared ones', function (): void {
    $html = '<p>Hello</p><div data-type="customBlock" data-id="hero" data-config="{&quot;heading&quot;:&quot;Welcome&quot;,&quot;image&quot;:&quot;/h.jpg&quot;}"></div>';
    $wine = makeRecord(Wine::class, ['en' => ['description' => $html]]);

    // Undeclared: the field can't be translated, and says so.
    $editor = openEditor('page', $wine, 'ka');

    expect($editor->html())->toContain(e(__('tipi-filament-translations::editor.unsupported.custom_block', ['block' => 'hero'])));

    configureEditor(fn (TranslationEditor $e) => $e->page()->customBlocks(['hero' => ['heading']]));

    openEditor('page', $wine, 'ka')
        ->fill('ka', ['description/0~0' => 'გამარჯობა', 'description/block0.heading' => 'მოგესალმებით'])
        ->save()
        ->assertSaved();

    expect(readerSees($wine, 'description'))->toContain('data-config="{&quot;heading&quot;:&quot;მოგესალმებით&quot;,&quot;image&quot;:&quot;/h.jpg&quot;}"');
});

it('translates repeater items by id and keeps their other data', function (): void {
    $wine = makeRecord(Wine::class, ['en' => ['notes' => [
        ['id' => 'cherry', 'note' => 'Cherry', 'score' => 9],
        ['id' => 'plum', 'note' => 'Plum', 'score' => 7],
    ]]]);

    $editor = openEditor('page', $wine, 'ka');

    // The translator gets texts, never the repeater's add, delete or reorder buttons.
    expect($editor->html())->not->toContain('fi-fo-repeater');

    $editor->fill('ka', ['notes/cherry/note' => 'ალუბალი', 'notes/plum/note' => 'ქლიავი'])->save()->assertSaved();

    expect(readerSees($wine, 'notes'))->toEqual([
        ['id' => 'cherry', 'note' => 'ალუბალი', 'score' => 9],
        ['id' => 'plum', 'note' => 'ქლიავი', 'score' => 7],
    ]);

    // The author reorders, adds and removes items.
    $wine = $wine->fresh();
    $wine->setTranslation('notes', 'en', [
        ['id' => 'oak', 'note' => 'Oak', 'score' => 5],
        ['id' => 'cherry', 'note' => 'Cherry', 'score' => 9],
    ])->save();

    $reopened = openEditor('page', $wine->fresh(), 'ka');

    expect($reopened->text('ka', 'notes/cherry/note'))->toBe('ალუბალი')
        ->and($reopened->text('ka', 'notes/oak/note'))->toBeNull();

    $reopened->fill('ka', ['notes/oak/note' => 'მუხა'])->save()->assertSaved();

    expect(readerSees($wine, 'notes'))->toEqual([
        ['id' => 'oak', 'note' => 'მუხა', 'score' => 5],
        ['id' => 'cherry', 'note' => 'ალუბალი', 'score' => 9],
    ]);
});

it('refuses items it can’t tell apart, and says why', function (array $notes, string $reason): void {
    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi', 'notes' => $notes]]);

    $editor = openEditor('page', $wine, 'ka');

    expect($editor->html())->toContain(e(__("tipi-filament-translations::editor.unsupported.{$reason}", ['key' => 'id', 'position' => '2', 'id' => 'a'])));

    // The rest of the record can still be translated.
    $editor->fill('ka', ['name' => 'საფერავი'])->save()->assertSaved();

    expect($wine->fresh()->getTranslation('notes', 'ka'))->toBeNull();
})->with([
    'an item without an id' => [[['id' => 'a', 'note' => 'Cherry'], ['note' => 'Plum']], 'item_id_missing'],
    'a duplicated id' => [[['id' => 'a', 'note' => 'Cherry'], ['id' => 'a', 'note' => 'Plum']], 'item_id_duplicate'],
]);

it('matches fixed lists by position', function (): void {
    $grape = makeRecord(Grape::class, ['en' => ['notes' => [['label' => 'Early'], ['label' => 'Thick skin']]]]);

    openEditor('page', $grape, 'ka')
        ->fill('ka', ['notes/#1/label' => 'ადრეული', 'notes/#2/label' => 'სქელკანიანი'])
        ->save()
        ->assertSaved();

    expect(readerSees($grape, 'notes'))->toBe([['label' => 'ადრეული'], ['label' => 'სქელკანიანი']]);
});

it('translates builder blocks by type and keeps their settings', function (): void {
    $wine = makeRecord(Wine::class, ['en' => ['content' => [
        ['type' => 'heading', 'data' => ['id' => 'h', 'text' => 'Saperavi', 'level' => 'h2']],
        ['type' => 'image', 'data' => ['id' => 'i', 'url' => 'https://example.com/wine.jpg']],
        ['type' => 'quote', 'data' => ['id' => 'q', 'text' => "In vino\nveritas", 'author' => 'Pliny']],
    ]]]);

    $editor = openEditor('page', $wine, 'ka');

    // The image block has no text: nothing to translate in it.
    expect($editor->html())->not->toContain('wine.jpg');

    // A single-line field can't take a line break.
    $editor->fill('ka', ['content/h/text' => "საფე\nრავი"])->save()->assertErrors(['ka' => ['content/h/text']]);

    $editor->fill('ka', ['content/h/text' => 'საფერავი', 'content/q/text' => "ღვინოში\nჭეშმარიტება", 'content/q/author' => 'პლინიუსი'])->save()->assertSaved();

    // toEqual: MySQL's JSON columns reorder keys.
    expect(readerSees($wine, 'content'))->toEqual([
        ['type' => 'heading', 'data' => ['id' => 'h', 'text' => 'საფერავი', 'level' => 'h2']],
        ['type' => 'image', 'data' => ['id' => 'i', 'url' => 'https://example.com/wine.jpg']],
        ['type' => 'quote', 'data' => ['id' => 'q', 'text' => "ღვინოში\nჭეშმარიტება", 'author' => 'პლინიუსი']],
    ]);
});

it('translates fixed simple repeaters item by item', function (): void {
    $region = makeRecord(Region::class, ['en' => ['notes' => ['Rolling hills', 'Alazani valley']]]);

    openEditor('page', $region, 'ka')
        ->fill('ka', ['notes/#2' => 'ალაზნის ველი'])
        ->save()
        ->assertSaved();

    // Half the list: a draft, so readers see the English list.
    expect(readerSees($region, 'notes'))->toBe(['Rolling hills', 'Alazani valley'])
        ->and($region->fresh()->getTranslationDraft('notes', 'ka'))->toBe([null, 'ალაზნის ველი']);
});

it('shows translations that no longer fit the source, without assigning them', function (): void {
    $grape = makeRecord(Grape::class, ['en' => ['description' => "First.\n\nSecond."]]);

    openEditor('page', $grape, 'ka')
        ->fill('ka', ['description/0' => 'პირველი.', 'description/1' => 'მეორე.'])
        ->save()
        ->assertSaved();

    // A paragraph is added at the top of the source.
    $grape = $grape->fresh();
    $grape->setTranslation('description', 'en', "Intro.\n\nFirst.\n\nSecond.")->save();

    $editor = openEditor('page', $grape->fresh(), 'ka');

    expect($editor->text('ka', 'description/0'))->toBeNull()
        ->and($editor->text('ka', 'description/1'))->toBeNull()
        ->and($editor->html())
        ->toContain('2 stored texts no longer match the source')
        ->toContain('პირველი.');

    // Untouched, the published translation stays as it is.
    $editor->fill('ka', ['name' => 'საფერავი'])->save()->assertSaved();

    expect(readerSees($grape, 'description'))->toBe("პირველი.\n\nმეორე.\n");
});

it('uses another language as reference without changing what is translated', function (): void {
    $wine = makeRecord(Wine::class, [
        'en' => ['name' => 'Saperavi', 'description' => '<p>Very <strong>dry</strong>.</p>'],
        'de' => ['name' => 'Saperawi', 'description' => '<p>Sehr <strong>trocken</strong>.</p>'],
    ]);

    $editor = openEditor('page', $wine, 'ka', ['reference' => 'de']);

    expect($editor->state('reference'))->toBe('de')
        ->and($editor->html())->toContain('Sehr <span class="fi-tipi-tr-tag"');

    $editor->fill('ka', ['description/0~0' => 'ძალიან <b1>მშრალი</b1>.'])->save()->assertSaved();

    expect(readerSees($wine, 'description'))->toBe('<p>ძალიან <strong>მშრალი</strong>.</p>');
});
