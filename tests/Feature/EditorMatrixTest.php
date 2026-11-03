<?php

declare(strict_types=1);

use Tipi\Translations\Filament\Tests\Fixtures\Models\Wine;

/*
 * Every presentation and layout, on every storage, shares one contract.
 */

beforeEach(function (): void {
    $this->signIn();
});

it('loads exactly what is stored, never fallback values', function (string $presentation, Closure $editor, string $model): void {
    configureEditor($editor);

    $record = makeRecord($model, [
        'en' => ['name' => 'Saperavi', 'description' => 'Dry red'],
        'ka' => ['name' => 'საფერავი'],
    ]);

    $editor = openEditor($presentation, $record, 'ka');

    expect($editor->state('locale'))->toBe('ka')
        ->and($editor->state('translations.ka.name'))->toBe('საფერავი')
        // Georgian has no description: the field is empty, not English.
        ->and($editor->state('loaded'))->toHaveKey('ka')
        ->and($record->fresh()->getTranslation('description', 'ka'))->toBeNull();

    $description = $editor->state('translations.ka.description');
    expect(is_array($description) ? json_encode($description) : (string) $description)->not->toContain('Dry red');
})->with(editorCombinations())->with(array_map(fn (array $storage): array => [$storage[0]], storages()));

it('saves the target locale without touching the source or other locales', function (string $presentation, Closure $editor, string $model): void {
    configureEditor($editor);

    $record = makeRecord($model, [
        'en' => ['name' => 'Saperavi', 'description' => 'Dry red'],
        'de' => ['name' => 'Saperawi'],
    ]);

    openEditor($presentation, $record, 'ka')
        ->fill('ka', ['name' => 'საფერავი'])
        ->save()
        ->assertSaved();

    $record = $record->fresh();

    expect($record->getTranslation('name', 'ka'))->toBe('საფერავი')
        ->and($record->getTranslation('description', 'ka'))->toBeNull()
        ->and($record->getTranslations('en'))->toBe(['name' => 'Saperavi', 'description' => 'Dry red'])
        ->and($record->getTranslations('de'))->toBe(['name' => 'Saperawi']);
})->with(editorCombinations())->with(array_map(fn (array $storage): array => [$storage[0]], storages()));

it('saves every opened locale in one save', function (string $presentation, Closure $editor, string $model): void {
    configureEditor($editor);

    $record = makeRecord($model, ['en' => ['name' => 'Saperavi']]);

    $editor = openEditor($presentation, $record, 'ka')
        ->fill('ka', ['name' => 'საფერავი'])
        ->switchTo('de');

    // Switching keeps unsaved changes and loads German as stored.
    expect($editor->state('translations.ka.name'))->toBe('საფერავი')
        ->and($editor->state('translations.de.name'))->toBeNull()
        ->and(array_keys($editor->state('loaded')))->toBe(['ka', 'de']);

    $editor->fill('de', ['name' => 'Saperawi'])->save()->assertSaved();

    expect($record->fresh()->getAllTranslations())->toMatchArray([
        'en' => ['name' => 'Saperavi'],
        'ka' => ['name' => 'საფერავი'],
        'de' => ['name' => 'Saperawi'],
    ]);
})->with(editorCombinations())->with(array_map(fn (array $storage): array => [$storage[0]], storages()));

it('validates every opened locale and saves nothing when one fails', function (string $presentation, Closure $editor): void {
    configureEditor($editor);

    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi'], 'ka' => ['name' => 'საფერავი']]);

    $editor = openEditor($presentation, $wine, 'ka')
        ->fill('ka', ['name' => 'ახალი'])
        ->switchTo('de')
        ->fill('de', ['name' => null]) // required
        ->save();

    $editor->assertErrors(['translations.de.name'])->assertStillOpen();

    // The input is kept and nothing is written.
    expect($editor->state('translations.ka.name'))->toBe('ახალი')
        ->and($wine->fresh()->getTranslation('name', 'ka'))->toBe('საფერავი');
})->with(editorCombinations());

it('applies locale-specific rules from the form', function (string $presentation, Closure $editor): void {
    configureEditor($editor);

    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi']]);

    // German names are limited to 20 characters, others to 255.
    openEditor($presentation, $wine, 'de')
        ->fill('de', ['name' => str_repeat('a', 21)])
        ->save()
        ->assertErrors(['translations.de.name']);

    openEditor($presentation, $wine, 'ka')
        ->fill('ka', ['name' => str_repeat('a', 21)])
        ->save()
        ->assertSaved();
})->with(editorCombinations());
