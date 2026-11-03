<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Grape;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Region;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Wine;
use Tipi\Translations\Filament\TranslationEditor;

beforeEach(function (): void {
    $this->signIn();
});

dataset('presentations', [
    'page' => ['page', fn (TranslationEditor $e) => $e->page()],
    'modal' => ['modal', fn (TranslationEditor $e) => $e->modal()],
]);

it('treats cleared fields as removed translations, so readers see the fallback', function (string $presentation, Closure $editor): void {
    configureEditor($editor);

    $wine = makeRecord(Wine::class, [
        'en' => ['name' => 'Saperavi'],
        'ka' => ['name' => 'საფერავი', 'description' => '<p>მშრალი</p>', 'notes' => [['note' => 'ალუბალი']]],
    ]);

    openEditor($presentation, $wine, 'ka')
        ->fill('ka', ['description' => '', 'notes' => []])
        ->save()
        ->assertSaved();

    expect($wine->fresh()->getTranslations('ka'))->toBe(['name' => 'საფერავი']);
})->with('presentations');

it('never stores a rich editor left empty', function (string $presentation, Closure $editor): void {
    configureEditor($editor);

    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi', 'description' => '<p>Dry</p>']]);

    openEditor($presentation, $wine, 'ka')->fill('ka', ['name' => 'საფერავი'])->save()->assertSaved();

    expect($wine->fresh()->hasTranslation('description', 'ka'))->toBeFalse()
        ->and($wine->fresh()->description)->toBe('<p>Dry</p>');
})->with('presentations');

it('round-trips rich text and repeaters', function (string $presentation, Closure $editor): void {
    configureEditor($editor);

    $wine = makeRecord(Wine::class, ['ka' => [
        'name' => 'საფერავი',
        'description' => '<p>მშრალი <strong>წითელი</strong></p>',
        'notes' => [['note' => 'ალუბალი'], ['note' => 'ქლიავი']],
    ]]);

    $editor = openEditor($presentation, $wine, 'ka');

    // Hydrated by the fields themselves: a document and keyed repeater items.
    expect($editor->state('translations.ka.description'))->toBeArray()
        ->and($editor->state('translations.ka.notes'))->toHaveCount(2);

    $editor->fill('ka', ['name' => 'საფერავი!'])->save()->assertSaved();

    expect($wine->fresh()->getTranslations('ka'))->toBe([
        'name' => 'საფერავი!',
        'description' => '<p>მშრალი <strong>წითელი</strong></p>',
        'notes' => [['note' => 'ალუბალი'], ['note' => 'ქლიავი']],
    ]);
})->with('presentations');

it('validates fields nested in repeaters', function (string $presentation, Closure $editor): void {
    configureEditor($editor);

    $wine = makeRecord(Wine::class, ['ka' => ['name' => 'საფერავი', 'notes' => [['note' => 'ალუბალი']]]]);

    $editor = openEditor($presentation, $wine, 'ka');
    $key = array_key_first($editor->state('translations.ka.notes'));

    $editor->set("translations.ka.notes.{$key}.note", null)->save()->assertErrors(["translations.ka.notes.{$key}.note"]);

    expect($wine->fresh()->getTranslation('notes', 'ka'))->toBe([['note' => 'ალუბალი']]);
})->with('presentations');

it('ignores attributes and locales the editor does not offer', function (string $presentation, Closure $editor): void {
    configureEditor($editor);

    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi', 'internal_code' => 'SAP-1']]);

    openEditor($presentation, $wine, 'ka')
        ->fill('ka', ['name' => 'საფერავი'])
        // Not in the form, though translatable.
        ->set('translations.ka.internal_code', 'HACK')
        // Not loaded: never written.
        ->set('translations.de', ['name' => 'Injected'])
        ->save()
        ->assertSaved();

    $wine = $wine->fresh();

    expect($wine->getTranslations('ka'))->toBe(['name' => 'საფერავი'])
        ->and($wine->getTranslation('internal_code', 'en'))->toBe('SAP-1')
        ->and($wine->hasTranslation('name', 'de'))->toBeFalse();
})->with('presentations');

it('refuses to write a locale injected into the loaded locales', function (string $presentation, Closure $editor): void {
    configureEditor($editor);

    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi']]);

    // French is inactive and not offered.
    $editor = openEditor($presentation, $wine, 'ka')
        ->fill('ka', ['name' => 'საფერავი'])
        ->set('loaded.fr', ['stored' => md5(serialize(['name' => null, 'description' => null, 'notes' => null]))])
        ->set('translations.fr', ['name' => 'Injecté']);

    $editor->save()->livewire->assertForbidden();

    // Nothing at all is written, not even the authorized locale.
    expect($wine->fresh()->getAllTranslations())->toBe(['en' => ['name' => 'Saperavi']]);
})->with('presentations');

it('keeps the editor open and saves nothing when another save changed the locale', function (string $presentation, Closure $editor): void {
    configureEditor($editor);

    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi'], 'ka' => ['name' => 'საფერავი']]);

    $editor = openEditor($presentation, $wine, 'ka')->fill('ka', ['name' => 'ჩემი']);

    // Another editor saves Georgian meanwhile.
    Wine::query()->find($wine->getKey())->setTranslation('description', 'ka', '<p>სხვისი</p>')->save();

    $editor->save()->assertStillOpen();
    $editor->livewire->assertNotified(__('tipi-filament-translations::editor.notifications.conflict_title'));

    expect($wine->fresh()->getTranslations('ka'))->toBe(['name' => 'საფერავი', 'description' => '<p>სხვისი</p>'])
        ->and($editor->state('translations.ka.name'))->toBe('ჩემი');
})->with('presentations');

it('does not conflict when another save changed a locale the editor did not open', function (string $presentation, Closure $editor): void {
    configureEditor($editor);

    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi']]);

    $editor = openEditor($presentation, $wine, 'ka')->fill('ka', ['name' => 'საფერავი']);

    Wine::query()->find($wine->getKey())->setTranslation('name', 'de', 'Saperawi')->save();

    $editor->save()->assertSaved();

    expect($wine->fresh()->getAllTranslations())->toMatchArray([
        'ka' => ['name' => 'საფერავი'],
        'de' => ['name' => 'Saperawi'],
    ]);
})->with('presentations');

it('reports when nothing changed', function (string $presentation, Closure $editor): void {
    configureEditor($editor);

    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi'], 'ka' => ['name' => 'საფერავი']]);
    $updatedAt = $wine->updated_at;

    $this->travel(1)->minute();

    openEditor($presentation, $wine, 'ka')->save()->assertSaved()
        ->livewire->assertNotified(__('tipi-filament-translations::editor.notifications.unchanged'));

    expect($wine->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();
})->with('presentations');

it('saves in one transaction with the model', function (): void {
    configureEditor(fn (TranslationEditor $e) => $e->page());

    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi']]);
    $editor = openEditor('page', $wine, 'ka')->fill('ka', ['name' => 'საფერავი'])->switchTo('de')->fill('de', ['name' => 'Saperawi']);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $editor->save()->assertSaved();

    $writes = array_values(array_filter($queries, fn (string $sql): bool => preg_match('/^(insert|update|delete)/i', $sql) === 1));

    // The model's updated_at, one upsert for both locales, and the states.
    expect(collect($writes)->filter(fn (string $sql): bool => str_contains($sql, 'wine_translations'))->count())->toBe(1);
});

it('copies the stored source value into the target field', function (string $presentation, Closure $editor): void {
    configureEditor(fn (TranslationEditor $e) => $editor($e)->comparison());

    $wine = makeRecord(Wine::class, ['en' => [
        'name' => 'Saperavi',
        'description' => '<p>Dry <strong>red</strong></p>',
        'notes' => [['note' => 'Cherry']],
    ]]);

    $editor = openEditor($presentation, $wine, 'ka');

    foreach (['description', 'notes'] as $attribute) {
        $editor->livewire->callAction(
            TestAction::make('copySource')->schemaComponent("tipi-translation-panel-ka.tipi-translation-ka-{$attribute}", schema: $presentation === 'page' ? 'form' : 'mountedActionSchema0'),
        );
    }

    $editor->fill('ka', ['name' => 'საფერავი'])->save()->assertSaved();

    expect($wine->fresh()->getTranslations('ka'))->toBe([
        'name' => 'საფერავი',
        'description' => '<p>Dry <strong>red</strong></p>',
        'notes' => [['note' => 'Cherry']],
    ]);
})->with('presentations');

it('switches the source and keeps editing locales out of the source options', function (string $presentation, Closure $editor): void {
    configureEditor(fn (TranslationEditor $e) => $editor($e)->comparison());

    $wine = makeRecord(Wine::class, [
        'en' => ['name' => 'Saperavi'],
        'de' => ['name' => 'Saperawi'],
    ]);

    $editor = openEditor($presentation, $wine, 'ka');

    expect($editor->state('source_locale'))->toBe('en')
        ->and($editor->state('source.name'))->toBe('Saperavi');

    $editor->source('de');

    expect($editor->state('source_locale'))->toBe('de')
        ->and($editor->state('source.name'))->toBe('Saperawi');

    // Georgian is being edited: it can't become the read-only source.
    $editor->source('ka');

    expect($editor->state('source_locale'))->toBe('de');

    // The source is never written, even when its state is changed.
    $editor->set('source.name', 'Changed')->fill('ka', ['name' => 'საფერავი'])->save()->assertSaved();

    expect($wine->fresh()->getTranslation('name', 'de'))->toBe('Saperawi');
})->with('presentations');

it('shows a missing source honestly instead of substituting another locale', function (string $presentation, Closure $editor): void {
    configureEditor(fn (TranslationEditor $e) => $editor($e)->comparison()->sourceLocale('de'));

    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi']]);

    $editor = openEditor($presentation, $wine, 'ka');

    expect($editor->state('source_locale'))->toBe('de')
        ->and($editor->state('source.name'))->toBeNull();
})->with('presentations');

it('opens the source and target locales from the query string', function (): void {
    configureEditor(fn (TranslationEditor $e) => $e->page()->comparison());

    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi'], 'de' => ['name' => 'Saperawi']]);

    $editor = openEditor('page', $wine, 'ka', ['source' => 'de']);

    expect($editor->state('locale'))->toBe('ka')->and($editor->state('source_locale'))->toBe('de');

    $editor->switchTo('ar');

    $editor->livewire->assertSet('activeLocale', 'ar')->assertSet('sourceLocale', 'de');
});

it('ignores unknown or unauthorized locales in links', function (string $presentation, Closure $editor): void {
    configureEditor($editor);

    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi']]);

    $editor = openEditor($presentation, $wine, 'xx');

    expect($editor->state('locale'))->toBeIn(['ka', 'de', 'ar']);

    $editor->switchTo('fr');

    expect($editor->state('locale'))->not->toBe('fr')
        ->and($editor->state('loaded'))->not->toHaveKey('fr');
})->with('presentations');

it('deletes a translation from the editor', function (string $presentation, Closure $editor): void {
    configureEditor($editor);

    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi'], 'ka' => ['name' => 'საფერავი']]);

    $editor = openEditor($presentation, $wine, 'ka');

    $editor->livewire->callAction(TestAction::make('deleteTranslation')->schemaComponent('tipi-translation-panel-ka', schema: $presentation === 'page' ? 'form' : 'mountedActionSchema0'));

    expect($wine->fresh()->getTranslations('ka'))->toBe([])
        ->and($editor->state('translations.ka.name'))->toBeNull();
})->with('presentations');

it('works the same for every storage on the page', function (string $model): void {
    configureEditor(fn (TranslationEditor $e) => $e->page()->localeTabs());

    $record = makeRecord($model, ['en' => ['name' => 'Kakheti']]);

    $editor = openEditor('page', $record, 'ka')->fill('ka', ['name' => 'კახეთი', 'description' => 'აღმოსავლეთი']);
    $editor->save()->assertSaved();

    expect($record->fresh()->getTranslations('ka'))->toBe(['name' => 'კახეთი', 'description' => 'აღმოსავლეთი']);
})->with([Grape::class, Region::class]);
