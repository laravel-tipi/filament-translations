<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tipi\Translations\Filament\Content\Segment;
use Tipi\Translations\Filament\Editor\RecordEditor;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Wine;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\WineResource;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\Wines\ListWines;
use Tipi\Translations\Filament\TranslationEditor;

/*
 * Freshness: a translated text is outdated when its source text changed
 * since it was translated or confirmed. Each segment records the
 * fingerprint of the source text it was made from; nothing else is
 * stored, and completeness is separate.
 */

beforeEach(function (): void {
    $this->signIn();
    configureEditor(fn (TranslationEditor $e) => $e->page()->comparison());
});

function translatedWine(): Wine
{
    $wine = makeRecord(Wine::class, ['en' => [
        'name' => 'Saperavi',
        'description' => '<p>Dry <a href="/a">red</a>.</p><p>Ages well.</p>',
        'notes' => [['id' => 'a', 'note' => 'Cherry', 'score' => 9], ['id' => 'b', 'note' => 'Plum', 'score' => 7]],
    ]]);

    openEditor('page', $wine, 'ka')->fill('ka', [
        'name' => 'საფერავი',
        'description/0~0' => 'მშრალი <a1>წითელი</a1>.',
        'description/1~0' => 'კარგად ძველდება.',
        'notes/a/note' => 'ალუბალი',
        'notes/b/note' => 'ქლიავი',
    ])->save()->assertSaved();

    return $wine->fresh();
}

/**
 * Outdated segment ids of a locale, read from the database.
 *
 * @return list<string>
 */
function staleSegments(Wine $wine, string $locale = 'ka'): array
{
    $editor = new RecordEditor(WineResource::getTranslationEditor(), $wine->fresh());
    $stale = [];

    foreach ($editor->attributes() as $attribute) {
        foreach (array_keys($editor->content()->stale($locale, $attribute)) as $path) {
            $stale[] = $path === '' ? $attribute : "{$attribute}/{$path}";
        }
    }

    return $stale;
}

function editSource(Wine $wine, array $values): void
{
    $wine->fresh()->setTranslations('en', $values)->save();
}

it('is current right after translating, across requests', function (): void {
    $wine = translatedWine();

    expect(staleSegments($wine))->toBe([])
        ->and((new RecordEditor(WineResource::getTranslationEditor(), $wine))->localeProgress('ka')->isOutdated())->toBeFalse();
});

it('flags exactly the segments whose source text changed', function (): void {
    $wine = translatedWine();

    // One paragraph and one item reworded; the structure is unchanged.
    editSource($wine, [
        'description' => '<p>Dry <a href="/a">red</a>.</p><p>Ages very well.</p>',
        'notes' => [['id' => 'a', 'note' => 'Cherry', 'score' => 9], ['id' => 'b', 'note' => 'Ripe plum', 'score' => 7]],
    ]);

    expect(staleSegments($wine))->toBe(['description/1~0', 'notes/b/note']);

    $progress = (new RecordEditor(WineResource::getTranslationEditor(), $wine->fresh()))->localeProgress('ka');

    expect($progress->isOutdated())->toBeTrue()
        ->and($progress->isMarkedOutdated())->toBeFalse()
        // Still complete: freshness is not completeness.
        ->and($progress->missing())->toBe(0)
        ->and($progress->summary())->toContain('2 texts are outdated');

    $html = openEditor('page', $wine->fresh(), 'ka')->html();

    expect($html)->toContain(__('tipi-filament-translations::editor.row.stale'))
        ->toContain(trans_choice('tipi-filament-translations::editor.attribute.stale', 1, ['count' => 1]));
});

it('ignores changes that aren’t text', function (): void {
    $wine = translatedWine();

    // A link target and an item's score change; no text does.
    editSource($wine, [
        'description' => '<p>Dry <a href="/elsewhere">red</a>.</p><p>Ages well.</p>',
        'notes' => [['id' => 'a', 'note' => 'Cherry', 'score' => 10], ['id' => 'b', 'note' => 'Plum', 'score' => 7]],
    ]);

    // Model metadata changes too.
    $wine->fresh()->forceFill(['slug' => 'saperavi-2020'])->save();

    expect(staleSegments($wine))->toBe([]);
});

it('is current again once the translation is updated', function (): void {
    $wine = translatedWine();
    editSource($wine, ['name' => 'Saperavi 2020']);

    expect(staleSegments($wine))->toBe(['name']);

    openEditor('page', $wine->fresh(), 'ka')->fill('ka', ['name' => 'საფერავი 2020'])->save()->assertSaved();

    expect(staleSegments($wine))->toBe([]);
});

it('stays outdated when other texts are saved', function (): void {
    $wine = translatedWine();
    editSource($wine, ['name' => 'Saperavi 2020', 'description' => '<p>Dry <a href="/a">red</a>.</p><p>Ages for years.</p>']);

    // Only the name is updated; the paragraph wasn't looked at.
    openEditor('page', $wine->fresh(), 'ka')->fill('ka', ['name' => 'საფერავი 2020'])->save()->assertSaved();

    expect(staleSegments($wine))->toBe(['description/1~0']);
});

it('lets the translator confirm a translation is still correct', function (): void {
    $wine = translatedWine();
    editSource($wine, ['description' => '<p>Dry <a href="/a">red</a>.</p><p>Ages well!</p>']);

    $editor = openEditor('page', $wine->fresh(), 'ka');

    expect($editor->html())->toContain(__('tipi-filament-translations::editor.row.still_correct'));

    // "Still correct", then save: the text is unchanged, its fingerprint is current.
    $editor->set('reviewed.ka.'.Segment::keyOf('description/1~0'), true)
        ->save()
        ->assertSaved();

    expect(staleSegments($wine))->toBe([])
        ->and($wine->fresh()->getTranslation('description', 'ka'))->toContain('კარგად ძველდება.');
});

it('marks a whole locale up to date', function (): void {
    $wine = translatedWine();
    editSource($wine, ['name' => 'Saperavi 2020', 'notes' => [['id' => 'a', 'note' => 'Sour cherry', 'score' => 9], ['id' => 'b', 'note' => 'Plum', 'score' => 7]]]);

    expect(staleSegments($wine))->toHaveCount(2);

    openEditor('page', $wine->fresh(), 'ka')->livewire
        ->callAction(TestAction::make('markCurrent')->schemaComponent('tipi-translation-panel-ka', schema: 'form'));

    expect(staleSegments($wine))->toBe([]);
});

it('tracks drafts too', function (): void {
    $wine = makeRecord(Wine::class, ['en' => ['description' => '<p>One.</p><p>Two.</p>']]);

    openEditor('page', $wine, 'ka')->fill('ka', ['description/0~0' => 'ერთი.'])->save()->assertSaved();

    editSource($wine, ['description' => '<p>One!</p><p>Two.</p>']);

    expect($wine->fresh()->hasTranslationDraft('description', 'ka'))->toBeTrue()
        ->and(staleSegments($wine))->toBe(['description/0~0']);
});

it('shows outdated locales in the table', function (): void {
    $wine = translatedWine();
    editSource($wine, ['name' => 'Saperavi 2020']);

    expect(Livewire::test(ListWines::class)->html())->toContain('1 text is outdated');
});
