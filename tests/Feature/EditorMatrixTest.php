<?php

declare(strict_types=1);

use Tipi\Translations\Filament\Tests\Fixtures\Models\Grape;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Region;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Wine;

/*
 * Every presentation and layout, on every storage, shares one contract.
 * Texts are addressed by segment id: the attribute, then its path.
 */

beforeEach(function (): void {
    $this->signIn();
});

/**
 * A description of each model's format, with two paragraphs.
 *
 * @return array{0: string, 1: string, 2: string} source, id of its first segment, id of its second
 */
function twoParagraphs(string $model): array
{
    return match ($model) {
        Wine::class => ['<p>Dry red.</p><p>Ages well.</p>', 'description/0~0', 'description/1~0'],
        Grape::class => ["Dry red.\n\nAges well.", 'description/0', 'description/1'],
        Region::class => ["Dry red.\n\nAges well.", 'description', 'description'],
    };
}

it('loads exactly what is stored, never fallback text', function (string $presentation, Closure $editor, string $model): void {
    configureEditor($editor);

    $record = makeRecord($model, [
        'en' => ['name' => 'Saperavi', 'description' => twoParagraphs($model)[0]],
        'ka' => ['name' => 'საფერავი'],
    ]);

    $editor = openEditor($presentation, $record, 'ka');

    expect($editor->state('locale'))->toBe('ka')
        ->and($editor->text('ka', 'name'))->toBe('საფერავი')
        // Georgian has no description: empty, not English.
        ->and($editor->text('ka', twoParagraphs($model)[1]))->toBeNull()
        ->and(json_encode($editor->state('translations.ka'), JSON_UNESCAPED_UNICODE))->not->toContain('Dry red');
})->with(editorCombinations())->with(array_map(fn (array $storage): array => [$storage[0]], storages()));

it('never offers the source as a target', function (string $presentation, Closure $editor): void {
    configureEditor($editor);

    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi']]);

    $editor = openEditor($presentation, $wine, 'en');

    expect($editor->state('locale'))->not->toBe('en')
        ->and($editor->state('loaded'))->not->toHaveKey('en');

    $editor->switchTo('en');

    expect($editor->state('locale'))->not->toBe('en');
})->with(editorCombinations());

it('saves the target without touching the source or other locales', function (string $presentation, Closure $editor, string $model): void {
    configureEditor($editor);

    [$description, $first] = twoParagraphs($model);

    $record = makeRecord($model, [
        'en' => ['name' => 'Saperavi', 'description' => $description],
        'de' => ['name' => 'Saperawi'],
    ]);

    openEditor($presentation, $record, 'ka')
        ->fill('ka', ['name' => 'საფერავი'])
        ->save()
        ->assertSaved();

    $record = $record->fresh();

    expect($record->getTranslation('name', 'ka'))->toBe('საფერავი')
        ->and($record->getTranslation('description', 'ka'))->toBeNull()
        ->and($record->getTranslations('en'))->toBe(['name' => 'Saperavi', 'description' => $description])
        ->and($record->getTranslations('de'))->toBe(['name' => 'Saperawi']);
})->with(editorCombinations())->with(array_map(fn (array $storage): array => [$storage[0]], storages()));

it('saves drafts: completeness is reported, never required', function (string $presentation, Closure $editor, string $model): void {
    configureEditor($editor);

    [$description] = twoParagraphs($model);
    $record = makeRecord($model, ['en' => ['name' => 'Saperavi', 'description' => $description]]);

    // The name is required() in the form; the draft leaves it out.
    openEditor($presentation, $record, 'ka')
        ->fill('ka', [twoParagraphs($model)[1] => 'მშრალი წითელი.'])
        ->save()
        ->assertSaved();

    expect($record->fresh()->getTranslation('name', 'ka'))->toBeNull()
        ->and($record->fresh()->getTranslation('description', 'ka'))->not->toBeNull();
})->with(editorCombinations())->with(array_map(fn (array $storage): array => [$storage[0]], storages()));

it('saves every opened locale in one save, and reopens what it saved', function (string $presentation, Closure $editor, string $model): void {
    configureEditor($editor);

    [$description, $first, $second] = twoParagraphs($model);
    $record = makeRecord($model, ['en' => ['name' => 'Saperavi', 'description' => $description]]);

    $editor = openEditor($presentation, $record, 'ka')
        ->fill('ka', ['name' => 'საფერავი', $first => 'მშრალი წითელი.'])
        ->switchTo('de');

    // Switching keeps unsaved texts and loads German as stored.
    expect($editor->text('ka', 'name'))->toBe('საფერავი')
        ->and($editor->text('de', 'name'))->toBeNull()
        ->and(array_keys($editor->state('loaded')))->toBe(['ka', 'de']);

    $editor->fill('de', ['name' => 'Saperawi'])->save()->assertSaved();

    expect($record->fresh()->getTranslations('de'))->toBe(['name' => 'Saperawi']);

    // Opened again: the saved texts, segment by segment.
    $reopened = openEditor($presentation, $record->fresh(), 'ka');

    expect($reopened->text('ka', 'name'))->toBe('საფერავი')
        ->and($reopened->text('ka', $first))->toBe('მშრალი წითელი.');

    if ($first !== $second) {
        expect($reopened->text('ka', $second))->toBeNull();
    }
})->with(editorCombinations())->with(array_map(fn (array $storage): array => [$storage[0]], storages()));

it('checks changed texts and saves nothing when one is invalid', function (string $presentation, Closure $editor): void {
    configureEditor($editor);

    $wine = makeRecord(Wine::class, [
        'en' => ['name' => 'Saperavi', 'description' => '<p>Very <strong>dry</strong>.</p>'],
        'ka' => ['name' => 'საფერავი'],
    ]);

    $editor = openEditor($presentation, $wine, 'ka')
        ->fill('ka', ['name' => 'ახალი'])
        ->switchTo('de')
        // The source's bold tag is missing.
        ->fill('de', ['description/0~0' => 'Sehr trocken.'])
        ->save();

    $editor->assertErrors(['de' => ['description/0~0']])->assertStillOpen();

    // The input is kept and nothing is written.
    expect($editor->text('ka', 'name'))->toBe('ახალი')
        ->and($wine->fresh()->getTranslation('name', 'ka'))->toBe('საფერავი');
})->with(editorCombinations());

it('neither checks nor saves locales opened without changes', function (string $presentation, Closure $editor): void {
    configureEditor($editor);

    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi']]);

    openEditor($presentation, $wine, 'ka')
        ->switchTo('de')
        ->switchTo('ar')
        ->switchTo('ka')
        ->fill('ka', ['name' => 'საფერავი'])
        ->save()
        ->assertSaved();

    expect($wine->fresh()->getTranslatedLocales())->toBe(['en', 'ka']);
})->with(editorCombinations());

it('applies locale-specific limits from the form', function (string $presentation, Closure $editor): void {
    configureEditor($editor);

    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi']]);

    // German names are limited to 20 characters, others to 255.
    openEditor($presentation, $wine, 'de')
        ->fill('de', ['name' => str_repeat('a', 21)])
        ->save()
        ->assertErrors(['de' => ['name']]);

    openEditor($presentation, $wine, 'ka')
        ->fill('ka', ['name' => str_repeat('a', 21)])
        ->save()
        ->assertSaved();
})->with(editorCombinations());

it('keeps placeholders', function (string $presentation, Closure $editor): void {
    configureEditor($editor);

    $grape = makeRecord(Grape::class, ['en' => ['name' => 'Hello :name']]);

    openEditor($presentation, $grape, 'ka')
        ->fill('ka', ['name' => 'გამარჯობა'])
        ->save()
        ->assertErrors(['ka' => ['name']]);

    expect($grape->fresh()->getTranslation('name', 'ka'))->toBeNull();
})->with(editorCombinations());
