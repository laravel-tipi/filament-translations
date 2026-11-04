<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tipi\Translations\Filament\Content\Segment;
use Tipi\Translations\Filament\Editor\RecordEditor;
use Tipi\Translations\Filament\Enums\TranslationStatus;
use Tipi\Translations\Filament\Support\LocaleProgress;
use Tipi\Translations\Filament\Tables\Filters\TranslationStatusFilter;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Wine;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\WineResource;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\Wines\ListWines;
use Tipi\Translations\Filament\TranslationEditor;

/*
 * Published translations and drafts: statuses, the table and its filter
 * describe what readers see; the editor shows that and the draft being
 * worked on apart; publishing never happens because the source changed,
 * and never shows readers an outdated text they didn't see before.
 */

beforeEach(function (): void {
    $this->signIn();
    configureEditor(fn (TranslationEditor $e) => $e->page()->comparison());
});

const TWO = '<p>One.</p><p>Two.</p>';

function editorOf(Wine $wine): RecordEditor
{
    return new RecordEditor(WineResource::getTranslationEditor(), $wine->fresh());
}

function readersProgress(Wine $wine, string $locale = 'ka'): LocaleProgress
{
    return editorOf($wine)->localeProgress($locale);
}

function changeSource(Wine $wine, array $values): void
{
    $wine->fresh()->setTranslations('en', $values)->save();
}

/**
 * @return list<string> paths of outdated texts, of the draft or of what is published
 */
function outdatedTexts(Wine $wine, string $attribute = 'description', bool $published = false): array
{
    return array_keys(editorOf($wine)->content()->stale('ka', $attribute, $published));
}

function publishedWine(string $description = TWO): Wine
{
    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi', 'description' => $description]]);

    openEditor('page', $wine, 'ka')->fill('ka', [
        'name' => 'საფერავი',
        'description/0~0' => 'ერთი.',
        'description/1~0' => 'ორი.',
    ])->save()->assertSaved();

    return $wine->fresh();
}

it('tells what readers see from the draft being worked on', function (): void {
    $wine = publishedWine();

    // A newer, unfinished version: one text reworded, one cleared.
    openEditor('page', $wine, 'ka')->fill('ka', ['description/0~0' => 'პირველი.', 'description/1~0' => null])->save()->assertSaved();

    $fresh = $wine->fresh();

    // Readers keep the complete published translation.
    expect(readerSees($wine, 'description'))->toBe('<p>ერთი.</p><p>ორი.</p>')
        ->and($fresh->hasTranslationDraft('description', 'ka'))->toBeTrue();

    $progress = readersProgress($wine);

    expect($progress->status())->toBe(TranslationStatus::Complete)
        ->and($progress->translated)->toBe(3)
        ->and($progress->drafted)->toBe(2)
        ->and($progress->drafts)->toBe(['description'])
        ->and($progress->hasDrafts())->toBeTrue()
        ->and($progress->needsWork())->toBeTrue()
        ->and($progress->summary())->toBe('Georgian: 3 of 3 texts published. Unpublished drafts: 2 of 3 texts translated.');

    $editor = openEditor('page', $fresh, 'ka');

    // The fields hold the draft; the notices say what readers see.
    expect($editor->text('ka', 'description/0~0'))->toBe('პირველი.')
        ->and($editor->text('ka', 'description/1~0'))->toBeNull()
        ->and($editor->html())
        ->toContain('3 of 3 published')
        ->toContain(__('tipi-filament-translations::editor.panel.drafted', ['drafted' => 2, 'total' => 3]))
        ->toContain(__('tipi-filament-translations::editor.attribute.draft'))
        ->toContain(__('tipi-filament-translations::editor.attribute.draft_incomplete'))
        ->toContain(__('tipi-filament-translations::editor.attribute.readers_published', ['translated' => 2, 'total' => 2]));

    expect(Livewire::test(ListWines::class)->html())->toContain('Unpublished drafts: 2 of 3 texts translated.');
});

it('never publishes a draft because the source changed', function (): void {
    $notes = [['id' => 'a', 'note' => 'Cherry'], ['id' => 'b', 'note' => 'Plum'], ['id' => 'c', 'note' => 'Oak']];
    $wine = makeRecord(Wine::class, ['en' => ['notes' => $notes]]);

    openEditor('page', $wine, 'ka')->fill('ka', ['notes/a/note' => 'ალუბალი', 'notes/b/note' => 'ქლიავი'])->save()->assertSaved();

    // The only untranslated item is removed from the source.
    changeSource($wine, ['notes' => array_slice($notes, 0, 2)]);

    $progress = readersProgress($wine);

    expect($wine->fresh()->getTranslation('notes', 'ka'))->toBeNull()
        ->and(readerSees($wine, 'notes'))->toEqual(array_slice($notes, 0, 2))
        ->and($progress->status())->toBe(TranslationStatus::Missing)
        ->and($progress->drafted)->toBe(2)
        ->and($progress->total)->toBe(2)
        ->and(editorOf($wine)->content()->draftStatus('ka', 'notes'))->toBe('ready');

    $editor = openEditor('page', $wine->fresh(), 'ka');

    expect($editor->html())
        ->toContain(__('tipi-filament-translations::editor.attribute.draft_ready'))
        ->toContain(__('tipi-filament-translations::editor.attribute.publish'));

    // Saving something else doesn't publish it either.
    $editor->fill('ka', ['name' => 'საფერავი'])->save()->assertSaved();

    expect($wine->fresh()->getTranslation('notes', 'ka'))->toBeNull();

    // Published when the translator asks.
    $editor = openEditor('page', $wine->fresh(), 'ka')->set('publish.ka.notes', true);

    expect($editor->html())->toContain(__('tipi-filament-translations::editor.attribute.publish_requested'));

    $editor->save()->assertSaved();

    expect(readerSees($wine, 'notes'))->toEqual([['id' => 'a', 'note' => 'ალუბალი'], ['id' => 'b', 'note' => 'ქლიავი']])
        ->and($wine->fresh()->hasTranslationDraft('notes', 'ka'))->toBeFalse()
        ->and(outdatedTexts($wine, 'notes', published: true))->toBe([]);
});

it('publishes only the draft the translator saw', function (): void {
    $notes = [['id' => 'a', 'note' => 'Cherry'], ['id' => 'b', 'note' => 'Plum'], ['id' => 'c', 'note' => 'Oak']];
    $wine = makeRecord(Wine::class, ['en' => ['notes' => $notes]]);

    openEditor('page', $wine, 'ka')->fill('ka', ['notes/a/note' => 'ალუბალი', 'notes/b/note' => 'ქლიავი'])->save()->assertSaved();
    changeSource($wine, ['notes' => array_slice($notes, 0, 2)]);

    $editor = openEditor('page', $wine->fresh(), 'ka')->set('publish.ka.notes', true);

    // Meanwhile, someone else changes the draft.
    openEditor('page', $wine->fresh(), 'ka')->fill('ka', ['notes/a/note' => null])->save()->assertSaved();

    $editor->save();

    expect($wine->fresh()->getTranslation('notes', 'ka'))->toBeNull()
        ->and($wine->fresh()->getTranslationDraft('notes', 'ka'))->toEqual([['id' => 'a', 'note' => null], ['id' => 'b', 'note' => 'ქლიავი']]);
});

it('treats a source structure change under a draft as a review, not a publication', function (): void {
    $wine = makeRecord(Wine::class, ['en' => ['description' => '<p>One.</p><p>Two.</p><p>Three.</p>']]);

    openEditor('page', $wine, 'ka')->fill('ka', ['description/0~0' => 'ერთი.', 'description/1~0' => 'ორი.'])->save()->assertSaved();

    // The document's structure changes: its texts no longer line up.
    changeSource($wine, ['description' => TWO]);

    $progress = readersProgress($wine);

    expect($wine->fresh()->getTranslation('description', 'ka'))->toBeNull()
        ->and(editorOf($wine)->content()->draftStatus('ka', 'description'))->toBe('incomplete')
        ->and(editorOf($wine)->alignment('ka', 'description')->orphans)->toBe(['ერთი.', 'ორი.'])
        ->and($progress->status())->toBe(TranslationStatus::Missing);
});

it('ignores a publish request for a draft that can’t be published', function (): void {
    $wine = makeRecord(Wine::class, ['en' => ['description' => TWO]]);

    openEditor('page', $wine, 'ka')->fill('ka', ['description/0~0' => 'ერთი.'])->save()->assertSaved();

    openEditor('page', $wine->fresh(), 'ka')->set('publish.ka.description', true)->save()->assertSaved();

    expect($wine->fresh()->getTranslation('description', 'ka'))->toBeNull()
        ->and($wine->fresh()->getTranslationDraft('description', 'ka'))->toBe('<p>ერთი.</p><p></p>');
});

it('never publishes outdated draft texts', function (): void {
    $wine = makeRecord(Wine::class, ['en' => ['description' => TWO]]);

    openEditor('page', $wine, 'ka')->fill('ka', ['description/0~0' => 'ერთი.'])->save()->assertSaved();

    // The drafted paragraph's source text changes.
    changeSource($wine, ['description' => '<p>One!</p><p>Two.</p>']);

    expect(outdatedTexts($wine))->toBe(['0~0']);

    // Completing the draft doesn't publish the outdated text.
    $editor = openEditor('page', $wine->fresh(), 'ka')->fill('ka', ['description/1~0' => 'ორი.'])->save()->assertSaved();

    expect($wine->fresh()->getTranslation('description', 'ka'))->toBeNull()
        ->and(readerSees($wine, 'description'))->toBe('<p>One!</p><p>Two.</p>')
        ->and(editorOf($wine)->content()->draftStatus('ka', 'description'))->toBe('outdated')
        ->and(openEditor('page', $wine->fresh(), 'ka')->html())
        ->toContain(trans_choice('tipi-filament-translations::editor.attribute.draft_outdated', 1, ['count' => 1]));

    // Confirmed as still correct, it is published.
    openEditor('page', $wine->fresh(), 'ka')
        ->set('reviewed.ka.'.Segment::keyOf('description/0~0'), true)
        ->save()
        ->assertSaved();

    expect(readerSees($wine, 'description'))->toBe('<p>ერთი.</p><p>ორი.</p>')
        ->and(outdatedTexts($wine, published: true))->toBe([]);
});

it('publishes changes to a translation whose outdated text readers already see', function (): void {
    $wine = publishedWine();
    changeSource($wine, ['description' => '<p>One!</p><p>Two.</p>']);

    // The other paragraph is improved; the outdated one is left as it is.
    openEditor('page', $wine->fresh(), 'ka')->fill('ka', ['description/1~0' => 'ორი!'])->save()->assertSaved();

    expect(readerSees($wine, 'description'))->toBe('<p>ერთი.</p><p>ორი!</p>')
        ->and($wine->fresh()->hasTranslationDraft('description', 'ka'))->toBeFalse()
        // Still outdated, and still reported.
        ->and(outdatedTexts($wine, published: true))->toBe(['0~0'])
        ->and(readersProgress($wine)->isOutdated())->toBeTrue();
});

it('keeps the freshness of what readers see while a draft exists', function (): void {
    $wine = publishedWine();

    // A draft: the second paragraph cleared.
    openEditor('page', $wine->fresh(), 'ka')->fill('ka', ['description/1~0' => null])->save()->assertSaved();

    changeSource($wine, ['description' => '<p>One!</p><p>Two.</p>']);

    expect(outdatedTexts($wine, published: true))->toBe(['0~0'])
        ->and(outdatedTexts($wine))->toBe(['0~0']);

    // The draft's text is updated: the draft is current, readers' isn't.
    openEditor('page', $wine->fresh(), 'ka')->fill('ka', ['description/0~0' => 'ერთი!'])->save()->assertSaved();

    $progress = readersProgress($wine);

    expect(outdatedTexts($wine))->toBe([])
        ->and(outdatedTexts($wine, published: true))->toBe(['0~0'])
        ->and($progress->stale)->toBe(1)
        ->and($progress->isOutdated())->toBeTrue()
        ->and(readerSees($wine, 'description'))->toBe('<p>ერთი.</p><p>ორი.</p>');
});

it('marks what readers see and the draft up to date', function (): void {
    $wine = publishedWine();
    openEditor('page', $wine->fresh(), 'ka')->fill('ka', ['description/1~0' => null])->save()->assertSaved();
    changeSource($wine, ['description' => '<p>One!</p><p>Two.</p>']);

    editorOf($wine)->markCurrent('ka');

    expect(outdatedTexts($wine))->toBe([])
        ->and(outdatedTexts($wine, published: true))->toBe([])
        ->and($wine->fresh()->getTranslationDraft('description', 'ka'))->toBe('<p>ერთი.</p><p></p>');
});

it('doesn’t vouch for translations written outside the editor', function (): void {
    $wine = publishedWine();

    // An import replaces the translation, made from who knows what.
    $wine->fresh()->setTranslation('description', 'ka', '<p>იმპორტი.</p><p>ორი.</p>')->save();
    changeSource($wine, ['description' => '<p>One!</p><p>Two!</p>']);

    expect($wine->fresh()->getTranslationSource('description', 'ka'))->toBeNull()
        ->and(outdatedTexts($wine, published: true))->toBe([])
        // The name kept its source: it was translated in the editor.
        ->and($wine->fresh()->getTranslationSource('name', 'ka'))->not->toBeNull();
});

/*
 * The filter: exactly the column's statuses.
 */

/**
 * @return array<string, Wine>
 */
function statusRecords(): array
{
    $records = [];

    $records['complete'] = publishedWine();

    // Every attribute has a published value, but the source gained a
    // paragraph since: incomplete, though no attribute is missing.
    $records['grown'] = publishedWine();
    changeSource($records['grown'], ['description' => '<p>One.</p><p>Two.</p><p>Three.</p>']);

    // Complete, with a text whose source changed: outdated automatically.
    $records['stale'] = publishedWine();
    changeSource($records['stale'], ['name' => 'Saperavi 2020']);

    $records['marked'] = publishedWine();
    $records['marked']->markTranslationOutdated('ka');

    // Only a draft: readers see English.
    $records['draft'] = makeRecord(Wine::class, ['en' => ['description' => TWO]]);
    openEditor('page', $records['draft'], 'ka')->fill('ka', ['description/0~0' => 'ერთი.'])->save()->assertSaved();

    $records['untranslated'] = makeRecord(Wine::class, ['en' => ['name' => 'Rkatsiteli']]);

    return array_map(fn (Wine $wine): Wine => $wine->fresh(), $records);
}

it('filters exactly what the column reports', function (): void {
    $records = statusRecords();

    $expected = [
        TranslationStatusFilter::COMPLETE => ['complete', 'stale', 'marked'],
        TranslationStatusFilter::INCOMPLETE => ['grown'],
        TranslationStatusFilter::MISSING => ['draft', 'untranslated'],
        TranslationStatusFilter::OUTDATED => ['stale', 'marked'],
        TranslationStatusFilter::DRAFTS => ['draft'],
    ];

    foreach ($expected as $status => $names) {
        // What the column reports for each record…
        $reported = array_keys(array_filter($records, fn (Wine $wine): bool => TranslationStatusFilter::matches(readersProgress($wine), $status)));

        expect($reported)->toBe($names, "column: {$status}");

        // …is what the filter finds.
        Livewire::test(ListWines::class)
            ->filterTable(TranslationStatusFilter::getDefaultName(), ['locale' => 'ka', 'status' => $status])
            ->assertCanSeeTableRecords(array_values(array_intersect_key($records, array_flip($names))))
            ->assertCanNotSeeTableRecords(array_values(array_diff_key($records, array_flip($names))));
    }
});

it('filters with a constant number of queries', function (): void {
    $count = function (int $records): int {
        Wine::query()->delete();

        foreach (range(1, $records) as $i) {
            publishedWine();
        }

        $component = Livewire::test(ListWines::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $component->filterTable(TranslationStatusFilter::getDefaultName(), ['locale' => 'ka', 'status' => TranslationStatusFilter::COMPLETE]);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect($count(2))->toBe($count(8));
});
