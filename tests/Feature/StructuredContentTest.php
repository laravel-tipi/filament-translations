<?php

declare(strict_types=1);

use League\CommonMark\GithubFlavoredMarkdownConverter;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Grape;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Region;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Wine;
use Tipi\Translations\Filament\TranslationEditor;

/*
 * Translators edit texts; the editor keeps the source's structure.
 * Every test checks what is stored and what the editor shows again.
 */

beforeEach(function (): void {
    $this->signIn();
    configureEditor(fn (TranslationEditor $e) => $e->page()->comparison());
});

it('translates Markdown block by block, with movable formatting', function (): void {
    $wine = makeRecord(Wine::class, ['en' => ['tasting' => "## Nose\n\nThis is **excellent** wine, see [the map](https://example.com/map).\n\n- Cherry\n- Plum\n\n```\nnot translated\n```"]]);

    $editor = openEditor('page', $wine, 'ka');

    expect($editor->html())
        ->toContain('This is <span class="fi-tipi-tr-tag" title="Bold">&lt;b1&gt;</span>excellent')
        ->toContain('title="Link: https://example.com/map"');

    $editor->fill('ka', [
        'tasting/0' => 'არომატი',
        // Georgian word order: the link comes first.
        'tasting/1' => '<a2>რუკაზე</a2> ნაჩვენები <b1>შესანიშნავი</b1> ღვინოა.',
        'tasting/2.0.0' => 'ალუბალი',
    ])->save()->assertSaved();

    $stored = $wine->fresh()->getTranslation('tasting', 'ka');
    $html = (new GithubFlavoredMarkdownConverter)->convert($stored)->getContent();

    expect($html)
        ->toContain('<h2>არომატი</h2>')
        ->toContain('<p><a href="https://example.com/map">რუკაზე</a> ნაჩვენები <strong>შესანიშნავი</strong> ღვინოა.</p>')
        ->toContain('<li>ალუბალი</li>')
        ->toContain("<pre><code>not translated\n</code></pre>");

    $reopened = openEditor('page', $wine->fresh(), 'ka');

    expect($reopened->text('ka', 'tasting/1'))->toBe('<a2>რუკაზე</a2> ნაჩვენები <b1>შესანიშნავი</b1> ღვინოა.')
        ->and($reopened->text('ka', 'tasting/2.1.0'))->toBeNull();
});

it('translates rich text without letting translators change its markup', function (): void {
    $wine = makeRecord(Wine::class, ['en' => ['description' => '<p class="lead">Dear <span data-type="mergeTag" data-id="name"></span>, try <a href="/shop" target="_blank">our <em>best</em> wine</a>.</p><p><img src="/v.jpg" alt="A vineyard"></p>']]);

    $editor = openEditor('page', $wine, 'ka')
        ->fill('ka', [
            'description/0~0' => '<script>alert(1)</script> ძვირფასო <span1/>, სცადეთ <a2>ჩვენი <i3>საუკეთესო</i3> ღვინო</a2>.',
            'description/img0' => 'ვენახი',
        ])
        ->save()
        ->assertSaved();

    expect($wine->fresh()->getTranslation('description', 'ka'))->toBe(
        '<p class="lead">&lt;script&gt;alert(1)&lt;/script&gt; ძვირფასო <span data-type="mergeTag" data-id="name"></span>, სცადეთ <a href="/shop" target="_blank">ჩვენი <em>საუკეთესო</em> ღვინო</a>.</p><p><img src="/v.jpg" alt="ვენახი"></p>',
    );

    // A missing tag is refused, with a reason, and nothing changes.
    $editor->fill('ka', ['description/0~0' => 'ძვირფასო, სცადეთ ღვინო.'])->save();

    $editor->assertErrors(['ka' => ['description/0~0']]);
    expect($editor->livewire->errors()->first('data.'.$editor::path('ka', 'description/0~0')))->toContain('<span1/>');
});

it('translates repeater items by id and keeps their other data', function (): void {
    $wine = makeRecord(Wine::class, ['en' => ['notes' => [
        ['id' => 'cherry', 'note' => 'Cherry', 'score' => 9],
        ['id' => 'plum', 'note' => 'Plum', 'score' => 7],
    ]]]);

    $editor = openEditor('page', $wine, 'ka');

    // The translator gets texts, never the repeater's add, delete or reorder buttons.
    expect($editor->html())->not->toContain('fi-fo-repeater');

    $editor->fill('ka', ['notes/cherry/note' => 'ალუბალი'])->save()->assertSaved();

    expect($wine->fresh()->getTranslation('notes', 'ka'))->toBe([
        ['id' => 'cherry', 'note' => 'ალუბალი', 'score' => 9],
        ['id' => 'plum', 'note' => null, 'score' => 7],
    ]);

    // The author reorders, adds and removes items in the source.
    $wine = $wine->fresh();
    $wine->setTranslation('notes', 'en', [
        ['id' => 'oak', 'note' => 'Oak', 'score' => 5],
        ['id' => 'cherry', 'note' => 'Cherry', 'score' => 9],
    ])->save();

    $reopened = openEditor('page', $wine->fresh(), 'ka');

    expect($reopened->text('ka', 'notes/cherry/note'))->toBe('ალუბალი')
        ->and($reopened->text('ka', 'notes/oak/note'))->toBeNull();

    $reopened->fill('ka', ['notes/oak/note' => 'მუხა'])->save()->assertSaved();

    expect($wine->fresh()->getTranslation('notes', 'ka'))->toBe([
        ['id' => 'oak', 'note' => 'მუხა', 'score' => 5],
        ['id' => 'cherry', 'note' => 'ალუბალი', 'score' => 9],
    ]);
});

it('matches repeater items without ids by position, never across changed items', function (): void {
    $grape = makeRecord(Grape::class, ['en' => ['notes' => [['label' => 'Early'], ['label' => 'Thick skin']]]]);

    openEditor('page', $grape, 'ka')
        ->fill('ka', ['notes/#1/label' => 'ადრეული', 'notes/#2/label' => 'სქელკანიანი'])
        ->save()
        ->assertSaved();

    expect($grape->fresh()->getTranslation('notes', 'ka'))->toBe([['label' => 'ადრეული'], ['label' => 'სქელკანიანი']]);

    // A third item: the first two keep their translations.
    $grape = $grape->fresh();
    $grape->setTranslation('notes', 'en', [['label' => 'Early'], ['label' => 'Thick skin'], ['label' => 'Low yield']])->save();

    $reopened = openEditor('page', $grape->fresh(), 'ka');

    expect($reopened->text('ka', 'notes/#1/label'))->toBe('ადრეული')
        ->and($reopened->text('ka', 'notes/#3/label'))->toBeNull();
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

    $editor->fill('ka', ['content/h/text' => 'საფერავი', 'content/q/text' => "ღვინოში\nჭეშმარიტება"])->save()->assertSaved();

    expect($wine->fresh()->getTranslation('content', 'ka'))->toBe([
        ['type' => 'heading', 'data' => ['id' => 'h', 'text' => 'საფერავი', 'level' => 'h2']],
        ['type' => 'image', 'data' => ['id' => 'i', 'url' => 'https://example.com/wine.jpg']],
        ['type' => 'quote', 'data' => ['id' => 'q', 'text' => "ღვინოში\nჭეშმარიტება", 'author' => null]],
    ]);

    // A single-line field can't take a line break.
    $editor->fill('ka', ['content/h/text' => "საფე\nრავი"])->save()->assertErrors(['ka' => ['content/h/text']]);
});

it('translates simple repeaters item by item', function (): void {
    $region = makeRecord(Region::class, ['en' => ['notes' => ['Rolling hills', 'Alazani valley']]]);

    openEditor('page', $region, 'ka')
        ->fill('ka', ['notes/#2' => 'ალაზნის ველი'])
        ->save()
        ->assertSaved();

    expect($region->fresh()->getTranslation('notes', 'ka'))->toBe([null, 'ალაზნის ველი']);
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

    // Untouched, the stored translation stays as it is.
    $editor->fill('ka', ['name' => 'საფერავი'])->save()->assertSaved();

    expect($grape->fresh()->getTranslation('description', 'ka'))->toBe("პირველი.\n\nმეორე.\n");
});

it('uses another language as reference without changing what is translated', function (): void {
    $wine = makeRecord(Wine::class, [
        'en' => ['name' => 'Saperavi', 'description' => '<p>Very <strong>dry</strong>.</p>'],
        'de' => ['name' => 'Saperawi', 'description' => '<p>Sehr <strong>trocken</strong>.</p>'],
    ]);

    $editor = openEditor('page', $wine, 'ka', ['reference' => 'de']);

    expect($editor->state('reference'))->toBe('de')
        ->and($editor->html())->toContain('Sehr <span class="fi-tipi-tr-tag"');

    // The segments are still the English source's.
    $editor->fill('ka', ['description/0~0' => 'ძალიან <b1>მშრალი</b1>.'])->save()->assertSaved();

    expect($wine->fresh()->getTranslation('description', 'ka'))->toBe('<p>ძალიან <strong>მშრალი</strong>.</p>');
});
