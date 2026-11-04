<?php

declare(strict_types=1);

use Tipi\Translations\Filament\Content\Segment;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Wine;
use Tipi\Translations\Filament\TranslationEditor;

/*
 * Real browser runs of representative workflows (Playwright, via
 * pestphp/pest-plugin-browser). Each checks what is stored afterwards.
 */

beforeEach(function (): void {
    $this->signIn();
});

function segment(string $id): string
{
    return '[data-segment="'.Segment::keyOf($id).'"] textarea';
}

/**
 * Click a locale tab, wherever the modal is scrolled.
 */
function clickTab(string $name): string
{
    return "[...document.querySelectorAll('.fi-tipi-tr-nav .fi-tabs-item')].find(tab => tab.textContent.includes('{$name}')).click()";
}

function richWine(): Wine
{
    return makeRecord(Wine::class, ['en' => [
        'name' => 'Saperavi',
        'description' => '<h2>A classic</h2><p>A <strong>dry red</strong>, aged in <a href="https://example.com/qvevri">qvevri</a>.</p>',
        'notes' => [['id' => 'cherry', 'note' => 'Black cherry', 'score' => 9]],
        'content' => [['type' => 'quote', 'data' => ['id' => 'q', 'text' => 'Where there is wine, there is life.', 'author' => 'Proverb']]],
    ]]);
}

it('copies the source, translates around its tags in another word order, and saves', function (): void {
    configureEditor(fn (TranslationEditor $e) => $e->page()->comparison());
    $wine = richWine();

    $page = visit('/admin/wines/'.$wine->getKey().'/translations?locale=ka')
        ->assertSee('Translate saperavi')
        ->assertPresent('.fi-tipi-tr-tag');

    // Copy the English paragraph, tags included, then rewrite it.
    $page->script('document.querySelector(\'[data-segment="'.Segment::keyOf('description/1~0').'"] .fi-tipi-tr-row-footer button\').click()');
    $page->wait(1)->assertValue(segment('description/1~0'), 'A <b1>dry red</b1>, aged in <a2>qvevri</a2>.');

    $page->fill(segment('description/0~0'), 'კლასიკა')
        ->fill(segment('description/1~0'), '<a2>ქვევრში</a2> დავარგებული <b1>მშრალი წითელი</b1>.')
        ->fill(segment('notes/cherry/note'), 'შავი ალუბალი')
        ->press('Save translations')
        ->assertSee('Translations saved')
        ->assertNoJavaScriptErrors();

    $wine = $wine->fresh();

    expect($wine->getTranslation('description', 'ka'))->toBe('<h2>კლასიკა</h2><p><a href="https://example.com/qvevri">ქვევრში</a> დავარგებული <strong>მშრალი წითელი</strong>.</p>')
        ->and($wine->getTranslation('notes', 'ka'))->toBe([['id' => 'cherry', 'note' => 'შავი ალუბალი', 'score' => 9]]);

    // After a reload the editor shows what was saved.
    visit('/admin/wines/'.$wine->getKey().'/translations?locale=ka')
        ->assertValue(segment('description/1~0'), '<a2>ქვევრში</a2> დავარგებული <b1>მშრალი წითელი</b1>.');
});

it('refuses broken tags with a reason and keeps the text', function (): void {
    configureEditor(fn (TranslationEditor $e) => $e->page()->comparison());
    $wine = richWine();

    visit('/admin/wines/'.$wine->getKey().'/translations?locale=ka')
        ->fill(segment('description/1~0'), 'მშრალი წითელი, ქვევრში.')
        ->press('Save translations')
        ->assertSee('Keep the source’s tags')
        ->assertValue(segment('description/1~0'), 'მშრალი წითელი, ქვევრში.');

    expect($wine->fresh()->getTranslation('description', 'ka'))->toBeNull();
});

it('translates builder blocks in the modal, switching locales without losing text', function (): void {
    configureEditor(fn (TranslationEditor $e) => $e->modal()->localeTabs());
    $wine = richWine();

    $page = visit('/admin/wines')
        ->click('Translate')
        ->assertSee('Translate saperavi')
        ->fill('.fi-modal-window [data-locale="ka"] '.segment('content/q/text'), 'სადაც ღვინოა, იქ სიცოცხლეა.')
        ->fill('.fi-modal-window [data-locale="ka"] '.segment('content/q/author'), 'ანდაზა');

    $page->script(clickTab('German'));
    $page->wait(1)->fill('.fi-modal-window [data-locale="de"] '.segment('name'), 'Saperawi');

    $page->script(clickTab('Georgian'));
    $page->wait(1)->assertValue('.fi-modal-window [data-locale="ka"] '.segment('content/q/text'), 'სადაც ღვინოა, იქ სიცოცხლეა.');

    $page->press('Save translations')->assertSee('Translations saved');

    $wine = $wine->fresh();

    expect($wine->getTranslation('content', 'ka'))->toBe([['type' => 'quote', 'data' => ['id' => 'q', 'text' => 'სადაც ღვინოა, იქ სიცოცხლეა.', 'author' => 'ანდაზა']]])
        ->and($wine->getTranslation('name', 'de'))->toBe('Saperawi');
});

it('shows a conflict, keeps the translator’s text and recovers with theirs', function (): void {
    configureEditor(fn (TranslationEditor $e) => $e->page()->comparison());
    $wine = richWine();

    $page = visit('/admin/wines/'.$wine->getKey().'/translations?locale=ka')
        ->fill(segment('name'), 'საპერავი');

    // Someone else saves the name meanwhile.
    $wine->fresh()->setTranslation('name', 'ka', 'საფერავი')->save();

    $page->press('Save translations')
        ->assertSee('Not saved: the content changed meanwhile')
        ->assertSee('Someone else saved this text while you were editing')
        ->assertValue(segment('name'), 'საპერავი');

    expect($wine->fresh()->getTranslation('name', 'ka'))->toBe('საფერავი');

    $page->click('Use their text')
        ->wait(1)
        ->assertValue(segment('name'), 'საფერავი')
        ->fill(segment('description/0~0'), 'კლასიკა')
        ->press('Save translations')
        ->assertSee('Translations saved');

    expect($wine->fresh()->getTranslation('name', 'ka'))->toBe('საფერავი')
        ->and($wine->fresh()->getTranslationDraft('description', 'ka'))->toBe('<h2>კლასიკა</h2><p></p>');
});

it('writes right-to-left languages right to left', function (): void {
    configureEditor(fn (TranslationEditor $e) => $e->page()->localeSelect());
    $wine = richWine();

    visit('/admin/wines/'.$wine->getKey().'/translations?locale=ar')
        ->assertAttribute(segment('name'), 'dir', 'rtl')
        ->assertAttribute(segment('name'), 'lang', 'ar')
        ->assertNoJavaScriptErrors();
});

it('never shows readers a partial translation, and publishes it once complete', function (): void {
    configureEditor(fn (TranslationEditor $e) => $e->page()->comparison());
    $wine = richWine();
    $site = '/site/ka/wines/'.$wine->getKey();

    visit('/admin/wines/'.$wine->getKey().'/translations?locale=ka')
        ->fill(segment('name'), 'საფერავი')
        ->fill(segment('description/0~0'), 'კლასიკა')
        ->press('Save translations')
        ->assertSee('Translations saved')
        ->assertSee('Draft, not published until every text is translated.');

    // Readers: the Georgian name, and the whole English description. No gaps.
    visit($site)
        ->assertSeeIn('#name', 'საფერავი')
        ->assertSeeIn('#description', 'A classic')
        ->assertSeeIn('#description', 'aged in qvevri')
        ->assertDontSeeIn('#description', 'კლასიკა');

    // The translator finishes from the draft.
    visit('/admin/wines/'.$wine->getKey().'/translations?locale=ka')
        ->assertValue(segment('description/0~0'), 'კლასიკა')
        ->fill(segment('description/1~0'), '<b1>მშრალი წითელი</b1>, დავარგებული <a2>ქვევრში</a2>.')
        ->press('Save translations')
        ->assertSee('Translations saved');

    visit($site)
        ->assertSeeIn('#description', 'კლასიკა')
        ->assertSeeIn('#description', 'ქვევრში')
        ->assertDontSeeIn('#description', 'A classic')
        ->assertPresent('#description a[href="https://example.com/qvevri"]');
});

it('flags a translation whose source changed, until it is confirmed', function (): void {
    configureEditor(fn (TranslationEditor $e) => $e->page()->comparison());
    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi']]);

    visit('/admin/wines/'.$wine->getKey().'/translations?locale=ka')
        ->fill(segment('name'), 'საფერავი')
        ->press('Save translations')
        ->assertSee('Translations saved');

    $wine->fresh()->setTranslation('name', 'en', 'Saperavi.')->save();

    $page = visit('/admin/wines/'.$wine->getKey().'/translations?locale=ka')
        ->assertSee('The source text changed since this was translated.')
        ->click('Still correct')
        ->assertSee('Confirmed as still correct')
        ->press('Save translations')
        ->assertSee('Translations saved');

    visit('/admin/wines/'.$wine->getKey().'/translations?locale=ka')
        ->assertDontSee('The source text changed since this was translated.')
        ->assertNoJavaScriptErrors();
});

it('says why content can’t be translated', function (): void {
    configureEditor(fn (TranslationEditor $e) => $e->page()->comparison());
    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi', 'notes' => [['note' => 'Cherry']]]]);

    visit('/admin/wines/'.$wine->getKey().'/translations?locale=ka')
        ->assertSee('Item 1 has no [id]');
});
