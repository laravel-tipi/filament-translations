<?php

declare(strict_types=1);

use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Livewire\Livewire;
use Tipi\Translations\Filament\Actions\TranslateAction;
use Tipi\Translations\Filament\Content\Segment;
use Tipi\Translations\Filament\Editor\RecordEditor;
use Tipi\Translations\Filament\Enums\TranslationStatus;
use Tipi\Translations\Filament\Forms\Components\ItemId;
use Tipi\Translations\Filament\Support\LocaleProgress;
use Tipi\Translations\Filament\Tables\Filters\TranslationStatusFilter;
use Tipi\Translations\Filament\Tests\Fixtures\EditorSettings;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Wine;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\WineResource;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\Wines\EditWine;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\Wines\ListWines;
use Tipi\Translations\Filament\TranslationEditor;

/*
 * Partial publishing: readers get each plain text as soon as it is
 * translated, and documents once they are complete. A save never takes
 * away from readers what it didn't publish or clear, and never shows them
 * a text translated from an older source text they didn't see before.
 *
 * The record is a page: an object with a nested object, and items with
 * a plain text and a Markdown document each.
 */

beforeEach(function (): void {
    $this->signIn();
    configurePage(fn (TranslationEditor $e) => $e->partialPublishing());
});

/**
 * @param  Closure(TranslationEditor): TranslationEditor  $configure
 */
function configurePage(Closure $configure): void
{
    configureEditor(fn (TranslationEditor $e) => $configure($e->page()->comparison()->form(
        fn (Schema $schema): Schema => $schema->components([
            TextInput::make('name'),
            TextInput::make('content.title'),
            TextInput::make('content.hero.heading'),
            Textarea::make('content.hero.lead'),
            Repeater::make('content.sections')->schema([
                ItemId::make(),
                TextInput::make('heading'),
                MarkdownEditor::make('body'),
            ]),
            Repeater::make('notes')->schema([
                ItemId::make(),
                TextInput::make('note'),
            ]),
        ]),
    )));
}

/**
 * Six plain texts, two of them in items.
 *
 * @return array<string, mixed>
 */
function sixTexts(): array
{
    return [
        'title' => 'Our story',
        'hero' => ['heading' => 'Since 1924', 'lead' => 'A family of winemakers.'],
        'sections' => [
            ['id' => 'a', 'heading' => 'Vineyards'],
            ['id' => 'b', 'heading' => 'Cellar'],
            ['id' => 'c', 'heading' => 'Shop'],
        ],
    ];
}

/**
 * Plain texts and documents.
 *
 * @return array<string, mixed>
 */
function mixedPage(): array
{
    return [
        'title' => 'Our story',
        'hero' => ['heading' => 'Since 1924', 'lead' => 'A family of winemakers.'],
        'sections' => [
            ['id' => 'a', 'heading' => 'Vineyards', 'body' => "Old vines.\n\nOn the hills."],
            ['id' => 'b', 'heading' => 'Cellar', 'body' => 'Qvevri.'],
        ],
    ];
}

function pageWine(array $content): Wine
{
    return makeRecord(Wine::class, ['en' => ['content' => $content]]);
}

function pageEditor(Wine $wine): RecordEditor
{
    return new RecordEditor(WineResource::getTranslationEditor(), $wine->fresh());
}

function pageProgress(Wine $wine, string $locale = 'ka'): LocaleProgress
{
    return pageEditor($wine)->localeProgress($locale);
}

/**
 * The published value of the page's content in the locale.
 *
 * @return array<string, mixed>|null
 */
function publishedContent(Wine $wine, string $locale = 'ka'): ?array
{
    return $wine->fresh()->getTranslation('content', $locale);
}

function changePage(Wine $wine, array $content): void
{
    $wine->fresh()->setTranslation('content', 'en', $content)->save();
}

it('publishes the translated texts of an incomplete translation', function (): void {
    $wine = pageWine(sixTexts());

    openEditor('page', $wine, 'ka')->fill('ka', [
        'content/title' => 'ჩვენი ისტორია',
        'content/hero/heading' => '1924 წლიდან',
        'content/hero/lead' => 'მეღვინეების ოჯახი.',
        'content/sections/b/heading' => 'მარანი',
    ])->save()->assertSaved();

    // Readers get the four texts; the two others are missing, not English.
    expect(publishedContent($wine))->toEqual([
        'title' => 'ჩვენი ისტორია',
        'hero' => ['heading' => '1924 წლიდან', 'lead' => 'მეღვინეების ოჯახი.'],
        'sections' => [
            ['id' => 'a', 'heading' => null],
            ['id' => 'b', 'heading' => 'მარანი'],
            ['id' => 'c', 'heading' => null],
        ],
    ])->and($wine->fresh()->hasTranslationDraft('content', 'ka'))->toBeFalse();

    $progress = pageProgress($wine);

    expect($progress->status())->toBe(TranslationStatus::Incomplete)
        ->and($progress->translated)->toBe(4)
        ->and($progress->total)->toBe(6)
        ->and($progress->drafts)->toBe([]);

    // The editor continues from what is published.
    $editor = openEditor('page', $wine->fresh(), 'ka');

    expect($editor->text('ka', 'content/sections/b/heading'))->toBe('მარანი')
        ->and($editor->text('ka', 'content/sections/a/heading'))->toBeNull()
        ->and($editor->html())->not->toContain(__('tipi-filament-translations::editor.attribute.draft'));
});

it('keeps an incomplete translation as a draft without partial publishing', function (): void {
    configurePage(fn (TranslationEditor $e) => $e);
    $wine = pageWine(sixTexts());

    openEditor('page', $wine, 'ka')->fill('ka', ['content/title' => 'ჩვენი ისტორია'])->save()->assertSaved();

    expect(publishedContent($wine))->toBeNull()
        ->and($wine->fresh()->getTranslationDraft('content', 'ka'))->toMatchArray(['title' => 'ჩვენი ისტორია'])
        ->and(pageProgress($wine)->status())->toBe(TranslationStatus::Missing);
});

it('publishes each locale on its own', function (): void {
    $wine = pageWine(sixTexts());

    openEditor('page', $wine, 'ka')
        ->fill('ka', ['content/title' => 'ჩვენი ისტორია'])
        ->switchTo('de')
        ->fill('de', ['content/hero/heading' => 'Seit 1924'])
        ->save()
        ->assertSaved();

    expect(publishedContent($wine, 'ka'))->toMatchArray(['title' => 'ჩვენი ისტორია', 'hero' => ['heading' => null, 'lead' => null]])
        ->and(publishedContent($wine, 'de'))->toMatchArray(['title' => null, 'hero' => ['heading' => 'Seit 1924', 'lead' => null]])
        ->and(pageProgress($wine, 'ka')->translated)->toBe(1)
        ->and(pageProgress($wine, 'de')->translated)->toBe(1);
});

it('keeps published texts a save doesn’t touch', function (): void {
    $wine = pageWine(sixTexts());

    openEditor('page', $wine, 'ka')->fill('ka', ['content/title' => 'ჩვენი ისტორია', 'content/hero/heading' => '1924 წლიდან'])->save()->assertSaved();
    openEditor('page', $wine->fresh(), 'ka')->fill('ka', ['content/hero/lead' => 'მეღვინეების ოჯახი.'])->save()->assertSaved();

    expect(publishedContent($wine))->toMatchArray([
        'title' => 'ჩვენი ისტორია',
        'hero' => ['heading' => '1924 წლიდან', 'lead' => 'მეღვინეების ოჯახი.'],
    ]);
});

it('never erases published texts a submission leaves out', function (): void {
    $wine = pageWine(sixTexts());

    openEditor('page', $wine, 'ka')->fill('ka', ['content/title' => 'ჩვენი ისტორია', 'content/hero/heading' => '1924 წლიდან'])->save()->assertSaved();

    // A submission with only one text: the others are not cleared.
    openEditor('page', $wine->fresh(), 'ka')
        ->set('translations.ka', [Segment::keyOf('content/hero/lead') => 'მეღვინეების ოჯახი.'])
        ->save()
        ->assertSaved();

    expect(publishedContent($wine))->toMatchArray([
        'title' => 'ჩვენი ისტორია',
        'hero' => ['heading' => '1924 წლიდან', 'lead' => 'მეღვინეების ოჯახი.'],
    ]);
});

it('never erases published texts a draft doesn’t have', function (): void {
    $wine = pageWine(sixTexts());

    openEditor('page', $wine, 'ka')->fill('ka', ['content/title' => 'ჩვენი ისტორია'])->save()->assertSaved();

    // Published outside the editor while a draft without it exists.
    $wine = $wine->fresh();
    $wine->setTranslationDraft('content', 'ka', ['title' => null, 'hero' => ['heading' => null, 'lead' => null], 'sections' => [['id' => 'a', 'heading' => 'ვენახები']]])->save();

    openEditor('page', $wine->fresh(), 'ka')->fill('ka', ['content/hero/heading' => '1924 წლიდან'])->save()->assertSaved();

    expect(publishedContent($wine))->toMatchArray(['title' => 'ჩვენი ისტორია', 'hero' => ['heading' => '1924 წლიდან', 'lead' => null]])
        ->and(publishedContent($wine)['sections'][0])->toEqual(['id' => 'a', 'heading' => 'ვენახები']);
});

it('removes the texts a save clears', function (): void {
    $wine = pageWine(sixTexts());

    openEditor('page', $wine, 'ka')->fill('ka', ['content/title' => 'ჩვენი ისტორია', 'content/hero/heading' => '1924 წლიდან'])->save()->assertSaved();
    openEditor('page', $wine->fresh(), 'ka')->fill('ka', ['content/title' => null])->save()->assertSaved();

    expect(publishedContent($wine))->toMatchArray(['title' => null, 'hero' => ['heading' => '1924 წლიდან', 'lead' => null]])
        ->and(array_keys($wine->fresh()->getTranslationSource('content', 'ka')))->toBe(['hero/heading'])
        ->and($wine->fresh()->hasTranslationDraft('content', 'ka'))->toBeFalse();

    // Clearing the last text removes the translation.
    openEditor('page', $wine->fresh(), 'ka')->fill('ka', ['content/hero/heading' => ''])->save()->assertSaved();

    expect(publishedContent($wine))->toBeNull()
        ->and($wine->fresh()->getTranslationSource('content', 'ka'))->toBeNull()
        ->and(pageProgress($wine)->status())->toBe(TranslationStatus::Missing);
});

it('records the source of each published text', function (): void {
    $wine = pageWine(sixTexts());

    openEditor('page', $wine, 'ka')->fill('ka', ['content/title' => 'ჩვენი ისტორია', 'content/sections/b/heading' => 'მარანი'])->save()->assertSaved();

    $segments = collect(pageEditor($wine)->content()->segments()['content'])->keyBy('path');

    expect($wine->fresh()->getTranslationSource('content', 'ka'))->toEqual([
        'title' => $segments['title']->hash(),
        'sections/b/heading' => $segments['sections/b/heading']->hash(),
    ]);
});

it('keeps published texts whose source changed, outdated, when saving others', function (): void {
    $wine = pageWine(sixTexts());

    openEditor('page', $wine, 'ka')->fill('ka', ['content/title' => 'ჩვენი ისტორია', 'content/hero/heading' => '1924 წლიდან'])->save()->assertSaved();

    $before = $wine->fresh()->getTranslationSource('content', 'ka');

    // The author rewords the title: the Georgian one stays published, outdated.
    changePage($wine, [...sixTexts(), 'title' => 'Our history']);

    expect(publishedContent($wine)['title'])->toBe('ჩვენი ისტორია')
        ->and(array_keys(pageEditor($wine)->content()->stale('ka', 'content', published: true)))->toBe(['title'])
        ->and(pageProgress($wine)->isOutdated())->toBeTrue();

    // Saving another text neither drops it nor makes it look current.
    openEditor('page', $wine->fresh(), 'ka')->fill('ka', ['content/hero/lead' => 'მეღვინეების ოჯახი.'])->save()->assertSaved();

    expect(publishedContent($wine))->toMatchArray(['title' => 'ჩვენი ისტორია', 'hero' => ['heading' => '1924 წლიდან', 'lead' => 'მეღვინეების ოჯახი.']])
        ->and($wine->fresh()->getTranslationSource('content', 'ka')['title'])->toBe($before['title'])
        ->and(array_keys(pageEditor($wine)->content()->stale('ka', 'content', published: true)))->toBe(['title']);

    // Updated, it is current again.
    openEditor('page', $wine->fresh(), 'ka')->fill('ka', ['content/title' => 'ჩვენი წარსული'])->save()->assertSaved();

    expect(publishedContent($wine)['title'])->toBe('ჩვენი წარსული')
        ->and(pageEditor($wine)->content()->stale('ka', 'content', published: true))->toBe([]);
});

it('keeps translations with their items when the source adds, removes and reorders them', function (): void {
    $wine = pageWine(sixTexts());

    openEditor('page', $wine, 'ka')->fill('ka', ['content/sections/a/heading' => 'ვენახები', 'content/sections/b/heading' => 'მარანი'])->save()->assertSaved();

    // Reordered, "b" removed, "d" added.
    changePage($wine, [...sixTexts(), 'sections' => [
        ['id' => 'd', 'heading' => 'Tours'],
        ['id' => 'c', 'heading' => 'Shop'],
        ['id' => 'a', 'heading' => 'Vineyards'],
    ]]);

    $editor = openEditor('page', $wine->fresh(), 'ka');

    expect($editor->text('ka', 'content/sections/a/heading'))->toBe('ვენახები')
        ->and($editor->text('ka', 'content/sections/d/heading'))->toBeNull();

    $editor->fill('ka', ['content/sections/d/heading' => 'ტურები'])->save()->assertSaved();

    // In the source's order, each text with its item; the removed item is gone.
    expect(publishedContent($wine)['sections'])->toEqual([
        ['id' => 'd', 'heading' => 'ტურები'],
        ['id' => 'c', 'heading' => null],
        ['id' => 'a', 'heading' => 'ვენახები'],
    ])->and(array_keys($wine->fresh()->getTranslationSource('content', 'ka')))->toEqualCanonicalizing(['sections/a/heading', 'sections/d/heading']);
});

it('merges the texts two editors publish, and refuses overwriting one', function (): void {
    $wine = pageWine(sixTexts());

    $alice = openEditor('page', $wine, 'ka');
    $bob = openEditor('page', $wine, 'ka');

    $alice->fill('ka', ['content/title' => 'ჩვენი ისტორია'])->save()->assertSaved();
    $bob->fill('ka', ['content/hero/heading' => '1924 წლიდან'])->save()->assertSaved();

    expect(publishedContent($wine))->toMatchArray(['title' => 'ჩვენი ისტორია', 'hero' => ['heading' => '1924 წლიდან', 'lead' => null]]);

    // Both change the same text: the second save is refused, whole.
    $alice = openEditor('page', $wine->fresh(), 'ka');
    $bob = openEditor('page', $wine->fresh(), 'ka');

    $alice->fill('ka', ['content/title' => 'ისტორია'])->save()->assertSaved();
    $bob->fill('ka', ['content/title' => 'ჩვენი ამბავი', 'content/hero/lead' => 'ოჯახი.'])->save();

    Notification::assertNotified(__('tipi-filament-translations::editor.notifications.conflict_title'));

    expect(publishedContent($wine))->toMatchArray(['title' => 'ისტორია', 'hero' => ['heading' => '1924 წლიდან', 'lead' => null]])
        ->and($bob->text('ka', 'content/title'))->toBe('ჩვენი ამბავი');
});

it('publishes plain texts beside a document only once the document is complete', function (): void {
    $wine = pageWine(mixedPage());

    openEditor('page', $wine, 'ka')->fill('ka', [
        'content/title' => 'ჩვენი ისტორია',
        'content/sections/a/heading' => 'ვენახები',
        'content/sections/a/body/0' => 'ძველი ვაზები.',
        'content/sections/b/body/0' => 'ქვევრი.',
    ])->save()->assertSaved();

    $wine = $wine->fresh();

    // The complete document is published; the half-translated one isn't.
    expect($wine->getTranslation('content', 'ka'))->toMatchArray(['title' => 'ჩვენი ისტორია'])
        ->and($wine->getTranslation('content', 'ka')['sections'])->toEqual([
            ['id' => 'a', 'heading' => 'ვენახები', 'body' => null],
            ['id' => 'b', 'heading' => null, 'body' => "ქვევრი.\n"],
        ])
        // The draft keeps it, with everything else.
        ->and($wine->getTranslationDraft('content', 'ka')['sections'][0]['body'])->toStartWith("ძველი ვაზები.\n")->not->toContain('hills')
        ->and(pageEditor($wine)->content()->draftStatus('ka', 'content'))->toBe('incomplete')
        ->and(pageProgress($wine)->translated)->toBe(3);

    $editor = openEditor('page', $wine, 'ka');

    expect($editor->text('ka', 'content/sections/a/body/0'))->toBe('ძველი ვაზები.')
        ->and($editor->html())->toContain(e(__('tipi-filament-translations::editor.attribute.draft_partial_incomplete')));

    // Completed, it is published and the draft is gone.
    $editor->fill('ka', ['content/sections/a/body/1' => 'ბორცვებზე.'])->save()->assertSaved();

    expect(publishedContent($wine)['sections'][0]['body'])->toBe("ძველი ვაზები.\n\nბორცვებზე.\n")
        ->and($wine->fresh()->hasTranslationDraft('content', 'ka'))->toBeFalse();
});

it('keeps a published document whole while it is being changed', function (): void {
    $wine = pageWine(mixedPage());

    openEditor('page', $wine, 'ka')->fill('ka', [
        'content/sections/a/body/0' => 'ძველი ვაზები.',
        'content/sections/a/body/1' => 'ბორცვებზე.',
    ])->save()->assertSaved();

    // A paragraph cleared, another reworded: the document is incomplete.
    openEditor('page', $wine->fresh(), 'ka')->fill('ka', [
        'content/sections/a/body/0' => 'ძველი ვაზი.',
        'content/sections/a/body/1' => null,
        'content/title' => 'ჩვენი ისტორია',
    ])->save()->assertSaved();

    expect(publishedContent($wine))->toMatchArray(['title' => 'ჩვენი ისტორია'])
        ->and(publishedContent($wine)['sections'][0]['body'])->toBe("ძველი ვაზები.\n\nბორცვებზე.\n")
        ->and($wine->fresh()->getTranslationDraft('content', 'ka')['sections'][0]['body'])->toStartWith("ძველი ვაზი.\n")->not->toContain('ბორცვებზე');

    // Clearing all of it removes it.
    openEditor('page', $wine->fresh(), 'ka')->fill('ka', ['content/sections/a/body/0' => null])->save()->assertSaved();

    expect(publishedContent($wine)['sections'][0]['body'])->toBeNull()
        ->and(publishedContent($wine)['title'])->toBe('ჩვენი ისტორია')
        ->and($wine->fresh()->hasTranslationDraft('content', 'ka'))->toBeFalse();
});

it('keeps what readers see of a document outdated while its update is a draft', function (): void {
    $wine = pageWine(mixedPage());

    openEditor('page', $wine, 'ka')->fill('ka', [
        'content/sections/a/body/0' => 'ძველი ვაზები.',
        'content/sections/a/body/1' => 'ბორცვებზე.',
    ])->save()->assertSaved();

    $changed = mixedPage();
    $changed['sections'][0]['body'] = "Very old vines.\n\nOn the hills.";
    changePage($wine, $changed);

    // The paragraph is updated, the other cleared: the update is a draft.
    openEditor('page', $wine->fresh(), 'ka')->fill('ka', [
        'content/sections/a/body/0' => 'ძალიან ძველი ვაზები.',
        'content/sections/a/body/1' => null,
    ])->save()->assertSaved();

    $content = pageEditor($wine)->content();

    expect(publishedContent($wine)['sections'][0]['body'])->toBe("ძველი ვაზები.\n\nბორცვებზე.\n")
        ->and(array_keys($content->stale('ka', 'content', published: true)))->toBe(['sections/a/body/0'])
        ->and($content->stale('ka', 'content'))->toBe([])
        ->and(pageProgress($wine)->isOutdated())->toBeTrue();
});

it('never publishes outdated draft texts, until they are updated or confirmed', function (): void {
    $wine = pageWine(mixedPage());

    openEditor('page', $wine, 'ka')->fill('ka', ['content/sections/a/body/0' => 'ძველი ვაზები.'])->save()->assertSaved();

    // The drafted paragraph's source text changes.
    $changed = mixedPage();
    $changed['sections'][0]['body'] = "Very old vines.\n\nOn the hills.";
    changePage($wine, $changed);

    // Completing the document doesn't publish the outdated paragraph.
    openEditor('page', $wine->fresh(), 'ka')->fill('ka', ['content/sections/a/body/1' => 'ბორცვებზე.', 'content/title' => 'ჩვენი ისტორია'])->save()->assertSaved();

    expect(publishedContent($wine)['title'])->toBe('ჩვენი ისტორია')
        ->and(publishedContent($wine)['sections'][0]['body'])->toBeNull()
        ->and(pageEditor($wine)->content()->draftStatus('ka', 'content'))->toBe('outdated')
        ->and(openEditor('page', $wine->fresh(), 'ka')->html())
        ->toContain(e(trans_choice('tipi-filament-translations::editor.attribute.draft_partial_outdated', 1, ['count' => 1])));

    // Confirmed as still correct, it is published.
    openEditor('page', $wine->fresh(), 'ka')
        ->set('reviewed.ka.'.Segment::keyOf('content/sections/a/body/0'), true)
        ->save()
        ->assertSaved();

    expect(publishedContent($wine)['sections'][0]['body'])->toBe("ძველი ვაზები.\n\nბორცვებზე.\n")
        ->and($wine->fresh()->hasTranslationDraft('content', 'ka'))->toBeFalse()
        ->and(pageEditor($wine)->content()->stale('ka', 'content', published: true))->toBe([]);
});

it('publishes completely only, when what is published no longer fits the source', function (): void {
    $wine = pageWine(mixedPage());

    openEditor('page', $wine, 'ka')->fill('ka', [
        'content/sections/a/body/0' => 'ძველი ვაზები.',
        'content/sections/a/body/1' => 'ბორცვებზე.',
    ])->save()->assertSaved();

    // The document gets another structure: its translation is an orphan,
    // which a partial publication would drop.
    $changed = mixedPage();
    $changed['sections'][0]['body'] = "Old vines.\n\n## Hills\n\nOn the hills.";
    changePage($wine, $changed);

    expect(pageEditor($wine)->content()->isPartiallyPublishable('ka', 'content'))->toBeFalse();

    openEditor('page', $wine->fresh(), 'ka')->fill('ka', ['content/title' => 'ჩვენი ისტორია'])->save()->assertSaved();

    // Readers keep the published translation; the change is a draft.
    expect(publishedContent($wine)['title'])->toBeNull()
        ->and(publishedContent($wine)['sections'][0]['body'])->toBe("ძველი ვაზები.\n\nბორცვებზე.\n")
        ->and($wine->fresh()->getTranslationDraft('content', 'ka')['title'])->toBe('ჩვენი ისტორია')
        ->and(openEditor('page', $wine->fresh(), 'ka')->html())->toContain(e(__('tipi-filament-translations::editor.attribute.draft_incomplete')));
});

it('publishes a draft saved before partial publishing when its attribute is saved, or on request', function (): void {
    configurePage(fn (TranslationEditor $e) => $e);
    $wine = pageWine(sixTexts());

    openEditor('page', $wine, 'ka')->fill('ka', ['content/title' => 'ჩვენი ისტორია'])->save()->assertSaved();

    configurePage(fn (TranslationEditor $e) => $e->partialPublishing());

    expect(publishedContent($wine))->toBeNull()
        ->and(pageEditor($wine)->content()->draftStatus('ka', 'content'))->toBe('ready')
        ->and(openEditor('page', $wine->fresh(), 'ka')->html())->toContain(e(__('tipi-filament-translations::editor.attribute.draft_partial_ready')));

    openEditor('page', $wine->fresh(), 'ka')->set('publish.ka.content', true)->save()->assertSaved();

    expect(publishedContent($wine)['title'])->toBe('ჩვენი ისტორია')
        ->and($wine->fresh()->hasTranslationDraft('content', 'ka'))->toBeFalse();
});

it('reports partial translations alike in the column and the filter', function (): void {
    $records = [];

    $records['partial'] = pageWine(sixTexts());
    openEditor('page', $records['partial'], 'ka')->fill('ka', [
        'content/title' => 'ჩვენი ისტორია',
        'content/hero/heading' => '1924 წლიდან',
        'content/hero/lead' => 'მეღვინეების ოჯახი.',
        'content/sections/a/heading' => 'ვენახები',
    ])->save()->assertSaved();

    $records['complete'] = pageWine(sixTexts());
    openEditor('page', $records['complete'], 'ka')->fill('ka', [
        'content/title' => 'ჩვენი ისტორია',
        'content/hero/heading' => '1924 წლიდან',
        'content/hero/lead' => 'მეღვინეების ოჯახი.',
        'content/sections/a/heading' => 'ვენახები',
        'content/sections/b/heading' => 'მარანი',
        'content/sections/c/heading' => 'მაღაზია',
    ])->save()->assertSaved();

    $records['untranslated'] = pageWine(sixTexts());

    $records = array_map(fn (Wine $wine): Wine => $wine->fresh(), $records);

    expect(pageProgress($records['partial'])->fraction())->toBe('4/6')
        ->and(Livewire::test(ListWines::class)->html())->toContain('Georgian: 4 of 6 texts published.');

    $expected = [
        TranslationStatusFilter::COMPLETE => ['complete'],
        TranslationStatusFilter::INCOMPLETE => ['partial'],
        TranslationStatusFilter::MISSING => ['untranslated'],
        TranslationStatusFilter::DRAFTS => [],
    ];

    foreach ($expected as $status => $names) {
        $reported = array_keys(array_filter($records, fn (Wine $wine): bool => TranslationStatusFilter::matches(pageProgress($wine), $status)));

        expect($reported)->toBe($names, "column: {$status}");

        Livewire::test(ListWines::class)
            ->filterTable(TranslationStatusFilter::getDefaultName(), ['locale' => 'ka', 'status' => $status])
            ->assertCanSeeTableRecords(array_values(array_intersect_key($records, array_flip($names))))
            ->assertCanNotSeeTableRecords(array_values(array_diff_key($records, array_flip($names))));
    }
});

it('publishes partially only the records and attributes the condition chooses', function (): void {
    configurePage(fn (TranslationEditor $e) => $e->partialPublishing(
        fn (Wine $wine, string $attribute): bool => $wine->slug === 'saperavi' && $attribute === 'content',
    ));

    $notes = [['id' => 'a', 'note' => 'Cherry'], ['id' => 'b', 'note' => 'Plum']];
    $wine = makeRecord(Wine::class, ['en' => ['content' => sixTexts(), 'notes' => $notes]]);

    openEditor('page', $wine, 'ka')->fill('ka', ['content/title' => 'ჩვენი ისტორია', 'notes/a/note' => 'ალუბალი'])->save()->assertSaved();

    expect(publishedContent($wine)['title'])->toBe('ჩვენი ისტორია')
        ->and($wine->fresh()->getTranslation('notes', 'ka'))->toBeNull()
        ->and($wine->fresh()->hasTranslationDraft('notes', 'ka'))->toBeTrue();

    $other = Wine::query()->create(['slug' => 'rkatsiteli']);
    $other->setTranslation('content', 'en', sixTexts())->save();

    openEditor('page', $other->fresh(), 'ka')->fill('ka', ['content/title' => 'ჩვენი ისტორია'])->save()->assertSaved();

    expect(publishedContent($other))->toBeNull()
        ->and($other->fresh()->hasTranslationDraft('content', 'ka'))->toBeTrue();
});

it('is configured per editor, off by default', function (): void {
    $wine = new Wine;

    expect(TranslationEditor::make()->hasPartialPublishing($wine, 'content'))->toBeFalse()
        ->and(TranslationEditor::make()->partialPublishing()->hasPartialPublishing($wine, 'content'))->toBeTrue()
        ->and(TranslationEditor::make()->partialPublishing()->partialPublishing(false)->hasPartialPublishing($wine, 'content'))->toBeFalse();
});

it('is set by the panel, then the resource, then the action', function (): void {
    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi']]);
    EditorSettings::$resource = null;
    EditorSettings::$panel = fn (TranslationEditor $e) => $e->partialPublishing();

    expect(WineResource::getTranslationEditor()->hasPartialPublishing($wine, 'content'))->toBeTrue();

    EditorSettings::$resource = fn (TranslationEditor $e) => $e->partialPublishing(false);

    expect(WineResource::getTranslationEditor()->hasPartialPublishing($wine, 'content'))->toBeFalse();

    $action = TranslateAction::make()
        ->livewire(Livewire::test(EditWine::class, ['record' => $wine->getRouteKey()])->instance())
        ->record($wine)
        ->editor(fn (TranslationEditor $editor) => $editor->partialPublishing(fn (Wine $record, string $attribute): bool => $attribute === 'content'));

    expect($action->getEditorConfig()->hasPartialPublishing($wine, 'content'))->toBeTrue()
        ->and($action->getEditorConfig()->hasPartialPublishing($wine, 'notes'))->toBeFalse();
});

it('publishes concurrent saves of other locales without touching them', function (): void {
    $wine = pageWine(sixTexts());

    openEditor('page', $wine, 'de')->fill('de', ['content/title' => 'Unsere Geschichte'])->save()->assertSaved();

    $wine = $wine->fresh();
    $german = [$wine->getTranslation('content', 'de'), $wine->getTranslationSource('content', 'de'), $wine->getTranslationDraft('content', 'de')];

    $georgian = openEditor('page', $wine, 'ka');
    $deutsch = openEditor('page', $wine, 'de');

    $georgian->fill('ka', ['content/title' => 'ჩვენი ისტორია'])->save()->assertSaved();

    $wine = $wine->fresh();

    // Saving Georgian left German exactly as it was.
    expect([$wine->getTranslation('content', 'de'), $wine->getTranslationSource('content', 'de'), $wine->getTranslationDraft('content', 'de')])->toBe($german);

    $deutsch->fill('de', ['content/hero/heading' => 'Seit 1924'])->save()->assertSaved();

    expect(publishedContent($wine, 'ka'))->toMatchArray(['title' => 'ჩვენი ისტორია', 'hero' => ['heading' => null, 'lead' => null]])
        ->and(publishedContent($wine, 'de'))->toMatchArray(['title' => 'Unsere Geschichte', 'hero' => ['heading' => 'Seit 1924', 'lead' => null]]);
});

it('keeps texts with their items when the source reorders them during a translation', function (): void {
    $wine = pageWine(sixTexts());

    openEditor('page', $wine, 'ka')->fill('ka', ['content/sections/a/heading' => 'ვენახები'])->save()->assertSaved();

    $editor = openEditor('page', $wine->fresh(), 'ka')->fill('ka', ['content/sections/b/heading' => 'მარანი']);

    // Meanwhile the author reorders the items.
    changePage($wine, [...sixTexts(), 'sections' => [
        ['id' => 'c', 'heading' => 'Shop'],
        ['id' => 'b', 'heading' => 'Cellar'],
        ['id' => 'a', 'heading' => 'Vineyards'],
    ]]);

    $editor->save()->assertSaved();

    // Published with its item, in the source's new order.
    expect(publishedContent($wine)['sections'])->toEqual([
        ['id' => 'c', 'heading' => null],
        ['id' => 'b', 'heading' => 'მარანი'],
        ['id' => 'a', 'heading' => 'ვენახები'],
    ]);
});

it('publishes completely only, when an item’s other data changed', function (): void {
    $page = sixTexts();
    $page['sections'][0]['image'] = 'vines.jpg';
    $wine = pageWine($page);

    openEditor('page', $wine, 'ka')->fill('ka', ['content/sections/a/heading' => 'ვენახები'])->save()->assertSaved();

    // Another image: the Georgian heading no longer fits the item.
    $page['sections'][0]['image'] = 'hills.jpg';
    changePage($wine, $page);

    expect(pageEditor($wine)->content()->isPartiallyPublishable('ka', 'content'))->toBeFalse();

    openEditor('page', $wine->fresh(), 'ka')->fill('ka', ['content/title' => 'ჩვენი ისტორია'])->save()->assertSaved();

    expect(publishedContent($wine)['sections'][0])->toEqual(['id' => 'a', 'heading' => 'ვენახები', 'image' => 'vines.jpg'])
        ->and(publishedContent($wine)['title'])->toBeNull()
        ->and($wine->fresh()->getTranslationDraft('content', 'ka')['title'])->toBe('ჩვენი ისტორია');
});

it('drops the texts of removed items and emptied source texts when the attribute is next saved', function (): void {
    $wine = pageWine(sixTexts());

    openEditor('page', $wine, 'ka')->fill('ka', [
        'content/hero/lead' => 'მეღვინეების ოჯახი.',
        'content/sections/b/heading' => 'მარანი',
    ])->save()->assertSaved();

    // The author removes item "b" and empties the lead.
    $page = sixTexts();
    $page['hero']['lead'] = null;
    unset($page['sections'][1]);
    $page['sections'] = array_values($page['sections']);
    changePage($wine, $page);

    // Until the attribute is saved, they stay as stored, listed in the editor.
    expect(publishedContent($wine)['hero']['lead'])->toBe('მეღვინეების ოჯახი.')
        ->and(openEditor('page', $wine->fresh(), 'ka')->html())->toContain('მეღვინეების ოჯახი.')->toContain('მარანი');

    openEditor('page', $wine->fresh(), 'ka')->fill('ka', ['content/title' => 'ჩვენი ისტორია'])->save()->assertSaved();

    // Saved, the value follows the source, as a complete save does.
    expect(publishedContent($wine))->toEqual([
        'title' => 'ჩვენი ისტორია',
        'hero' => ['heading' => null, 'lead' => null],
        'sections' => [['id' => 'a', 'heading' => null], ['id' => 'c', 'heading' => null]],
    ])->and(array_keys($wine->fresh()->getTranslationSource('content', 'ka')))->toBe(['title']);
});
