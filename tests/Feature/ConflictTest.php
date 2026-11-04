<?php

declare(strict_types=1);

use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tipi\Translations\Filament\Content\Segment;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Wine;
use Tipi\Translations\Filament\Tests\Fixtures\Models\WineTranslation;
use Tipi\Translations\Filament\TranslationEditor;

/*
 * Two editors opened on one record, saving one after the other. The
 * second save merges what doesn't overlap and refuses what does.
 */

beforeEach(function (): void {
    $this->signIn();
    configureEditor(fn (TranslationEditor $e) => $e->page()->comparison());
});

function wineWithText(): Wine
{
    return makeRecord(Wine::class, ['en' => [
        'name' => 'Saperavi',
        'description' => '<p>Dry.</p><p>Red.</p>',
        'notes' => [['id' => 'a', 'note' => 'Cherry'], ['id' => 'b', 'note' => 'Plum']],
    ]]);
}

it('keeps both editors’ changes to different locales, fields and segments', function (array $first, array $second): void {
    $wine = wineWithText();

    $alice = openEditor('page', $wine, array_key_first($first));
    $bob = openEditor('page', $wine, array_key_first($second));

    $alice->fill(array_key_first($first), reset($first))->save()->assertSaved();
    $bob->fill(array_key_first($second), reset($second))->save()->assertSaved();

    $wine = $wine->fresh();

    foreach ([$first, $second] as $change) {
        $editor = openEditor('page', $wine, array_key_first($change));

        foreach (reset($change) as $id => $text) {
            expect($editor->text(array_key_first($change), $id))->toBe($text);
        }
    }
})->with([
    'different locales' => [['ka' => ['name' => 'საფერავი']], ['de' => ['name' => 'Saperawi']]],
    'different fields' => [['ka' => ['name' => 'საფერავი']], ['ka' => ['description/0~0' => 'მშრალი.']]],
    'different paragraphs' => [['ka' => ['description/0~0' => 'მშრალი.']], ['ka' => ['description/1~0' => 'წითელი.']]],
    'different items' => [['ka' => ['notes/a/note' => 'ალუბალი']], ['ka' => ['notes/b/note' => 'ქლიავი']]],
]);

it('refuses to overwrite a text someone else saved, and keeps the translator’s', function (): void {
    $wine = wineWithText();

    $alice = openEditor('page', $wine, 'ka');
    $bob = openEditor('page', $wine, 'ka');

    $alice->fill('ka', ['description/0~0' => 'მშრალი.'])->save()->assertSaved();

    $bob->fill('ka', ['description/0~0' => 'მშრალია.', 'name' => 'საფერავი'])->save();

    Notification::assertNotified(__('tipi-filament-translations::editor.notifications.conflict_title'));

    // Nothing of Bob's save is written, not even the name.
    expect($wine->fresh()->getTranslation('name', 'ka'))->toBeNull()
        // Alice's half translation is a draft.
        ->and($wine->fresh()->getTranslationDraft('description', 'ka'))->toBe('<p>მშრალი.</p><p></p>')
        // His texts are still in the form, with Alice's shown.
        ->and($bob->text('ka', 'description/0~0'))->toBe('მშრალია.')
        ->and($bob->text('ka', 'name'))->toBe('საფერავი')
        ->and($bob->conflicts('ka')['segments'])->toHaveCount(1)
        ->and(array_values($bob->conflicts('ka')['segments'])[0])->toBe(['reason' => 'changed', 'theirs' => 'მშრალი.'])
        ->and($bob->html())->toContain(__('tipi-filament-translations::editor.row.conflict_changed'));

    // Saving again, having seen Alice's text, keeps his.
    $bob->save()->assertSaved();

    expect($wine->fresh()->getTranslation('name', 'ka'))->toBe('საფერავი')
        ->and($wine->fresh()->getTranslationDraft('description', 'ka'))->toBe('<p>მშრალია.</p><p></p>')
        ->and($bob->conflicts('ka')['segments'])->toBe([]);
});

it('lets the translator take the other text instead', function (): void {
    $wine = wineWithText();

    $alice = openEditor('page', $wine, 'ka');
    $bob = openEditor('page', $wine, 'ka');

    $alice->fill('ka', ['name' => 'საფერავი'])->save()->assertSaved();
    $bob->fill('ka', ['name' => 'საპერავი'])->save();

    // "Use their text" sets the field to it and clears the conflict.
    $key = Segment::keyOf('name');
    $bob->set("translations.ka.{$key}", 'საფერავი')->set("conflicts.ka.segments.{$key}", null);

    $bob->save()->assertSaved();

    expect($wine->fresh()->getTranslation('name', 'ka'))->toBe('საფერავი');
});

it('refuses a translation of a source text that changed meanwhile', function (): void {
    $wine = wineWithText();

    $bob = openEditor('page', $wine, 'ka');

    // The author rewrites the first paragraph.
    $wine->fresh()->setTranslation('description', 'en', '<p>Bone dry.</p><p>Red.</p>')->save();

    $bob->fill('ka', ['description/0~0' => 'მშრალი.'])->save();

    expect($wine->fresh()->hasTranslationDraft('description', 'ka'))->toBeFalse()
        ->and(array_values($bob->conflicts('ka')['segments'])[0]['reason'])->toBe('source')
        ->and($bob->html())->toContain('Bone dry.')->toContain(__('tipi-filament-translations::editor.row.conflict_source'));

    // Checked against the new source, the translation can be saved.
    $bob->save()->assertSaved();

    expect($wine->fresh()->getTranslationDraft('description', 'ka'))->toBe('<p>მშრალი.</p><p></p>');
});

it('refuses translations of a document whose structure changed meanwhile', function (): void {
    $wine = wineWithText();

    $bob = openEditor('page', $wine, 'ka');
    $bob->fill('ka', ['description/1~0' => 'წითელი.', 'notes/a/note' => 'ალუბალი']);

    // A paragraph is added before the one Bob translated.
    $wine->fresh()->setTranslation('description', 'en', '<p>Intro.</p><p>Dry.</p><p>Red.</p>')->save();

    $bob->save();

    // Nothing is assigned to the wrong paragraph, and nothing else is saved.
    expect($wine->fresh()->hasTranslationDraft('description', 'ka'))->toBeFalse()
        ->and($wine->fresh()->hasTranslationDraft('notes', 'ka'))->toBeFalse()
        // The text that no longer fits is kept to copy from; the others stay in place.
        ->and($bob->conflicts('ka')['attributes']['description'])->toBe(['წითელი.'])
        ->and($bob->text('ka', 'notes/a/note'))->toBe('ალუბალი')
        ->and($bob->html())->toContain(__('tipi-filament-translations::editor.attribute.unsaved'));
});

it('keeps translations with their items when the source reorders, adds or removes items meanwhile', function (): void {
    $wine = wineWithText();

    $bob = openEditor('page', $wine, 'ka');
    $bob->fill('ka', ['notes/a/note' => 'ალუბალი', 'notes/b/note' => 'ქლიავი']);

    // Meanwhile: reordered, one added.
    $wine->fresh()->setTranslation('notes', 'en', [
        ['id' => 'c', 'note' => 'Oak'],
        ['id' => 'b', 'note' => 'Plum'],
        ['id' => 'a', 'note' => 'Cherry'],
    ])->save();

    $bob->save()->assertSaved();

    // Not a conflict: items are matched by id. The new item is untranslated,
    // so the list is a draft.
    expect($wine->fresh()->getTranslationDraft('notes', 'ka'))->toBe([
        ['id' => 'c', 'note' => null],
        ['id' => 'b', 'note' => 'ქლიავი'],
        ['id' => 'a', 'note' => 'ალუბალი'],
    ]);
});

it('decides from what is stored when saving, not from what it loaded', function (): void {
    $wine = wineWithText();
    $bob = openEditor('page', $wine, 'ka')->fill('ka', ['name' => 'საპერავი']);

    // Another process saves right after this request loaded the record.
    Wine::retrieved(function (Wine $retrieved) use ($wine): void {
        static $done = false;

        if ($done || $retrieved->getKey() !== $wine->getKey()) {
            return;
        }

        $done = true;
        DB::table('wine_translations')->insert(['wine_id' => $wine->getKey(), 'locale_code' => 'ka', 'name' => 'საფერავი']);
    });

    try {
        $bob->save();
    } finally {
        Wine::flushEventListeners();
    }

    expect($wine->fresh()->getTranslation('name', 'ka'))->toBe('საფერავი')
        ->and(array_values($bob->conflicts('ka')['segments'])[0])->toBe(['reason' => 'changed', 'theirs' => 'საფერავი']);
});

it('tells when the record was deleted meanwhile', function (): void {
    $wine = wineWithText();
    $bob = openEditor('page', $wine, 'ka')->fill('ka', ['name' => 'საფერავი']);

    // Deleted once this request loaded the record, before saving: at the
    // first permission check.
    Gate::after(function () use ($wine): void {
        static $done = false;

        if (! $done) {
            $done = true;
            DB::table('wines')->where('id', $wine->getKey())->delete();
        }
    });

    $bob->save();

    Notification::assertNotified(__('tipi-filament-translations::editor.notifications.deleted_record'));

    expect(Wine::query()->count())->toBe(0)
        // Nothing was written for it (the raw delete skipped the cascade).
        ->and(WineTranslation::query()->where('locale_code', 'ka')->count())->toBe(0);
});

it('writes nothing when the transaction fails', function (): void {
    $wine = wineWithText();
    $bob = openEditor('page', $wine, 'ka')
        ->fill('ka', ['name' => 'საფერავი'])
        ->switchTo('de')
        ->fill('de', ['name' => 'Saperawi']);

    Wine::saved(fn () => throw new RuntimeException('Disk full'));

    try {
        expect(fn () => $bob->save())->toThrow(RuntimeException::class, 'Disk full');
    } finally {
        Wine::flushEventListeners();
    }

    expect($wine->fresh()->getTranslatedLocales())->toBe(['en']);
});

it('keeps translation states consistent with what was saved', function (): void {
    $wine = wineWithText();
    $wine->fresh()->setTranslation('name', 'ka', 'საფერავი')->save();

    $bob = openEditor('page', $wine->fresh(), 'ka');

    // Meanwhile, someone marks Georgian outdated.
    $wine->fresh()->markTranslationOutdated('ka');

    $bob->fill('ka', ['description/0~0' => 'მშრალი.', 'description/1~0' => 'წითელი.'])->save()->assertSaved();

    // Publishing the translation is a review of it: current again.
    expect($wine->fresh()->isTranslationOutdated('ka'))->toBeFalse();
});
