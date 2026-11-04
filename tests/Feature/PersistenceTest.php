<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Tipi\Translations\Filament\Content\Segment;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Wine;
use Tipi\Translations\Filament\TranslationEditor;

beforeEach(function (): void {
    $this->signIn();
});

dataset('presentations', [
    'page' => ['page', fn (TranslationEditor $e) => $e->page()],
    'modal' => ['modal', fn (TranslationEditor $e) => $e->modal()],
]);

it('treats cleared texts as removed translations, so readers see the fallback', function (string $presentation, Closure $editor): void {
    configureEditor($editor);

    $wine = makeRecord(Wine::class, [
        'en' => ['name' => 'Saperavi', 'description' => '<p>Dry.</p>'],
        'ka' => ['name' => 'საფერავი', 'description' => '<p>მშრალი.</p>'],
    ]);

    openEditor($presentation, $wine, 'ka')
        ->fill('ka', ['name' => '  ', 'description/0~0' => ''])
        ->save()
        ->assertSaved();

    $wine = $wine->fresh();

    expect($wine->getTranslations('ka'))->toBe([])
        // Neither an empty string nor an empty paragraph is stored.
        ->and($wine->resolveTranslation('name', 'ka')->isFallback())->toBeTrue();
})->with('presentations');

it('keeps a partly translated document as a draft, with empty blocks, never the source’s text', function (string $presentation, Closure $editor): void {
    configureEditor($editor);

    $wine = makeRecord(Wine::class, ['en' => ['description' => '<h2>Saperavi</h2><p>Dry.</p>']]);

    openEditor($presentation, $wine, 'ka')
        ->fill('ka', ['description/0~0' => 'საფერავი'])
        ->save()
        ->assertSaved();

    expect($wine->fresh()->getTranslation('description', 'ka'))->toBeNull()
        ->and($wine->fresh()->getTranslationDraft('description', 'ka'))->toBe('<h2>საფერავი</h2><p></p>');
})->with('presentations');

it('writes only the editor’s segments of offered, authorized locales', function (string $presentation, Closure $editor): void {
    $this->signIn(['translator_locales' => ['ka']]);
    configureEditor($editor);

    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi', 'internal_code' => 'X1']]);

    $editor = openEditor($presentation, $wine, 'ka')
        ->fill('ka', ['name' => 'საფერავი'])
        // Not segments of the source: a field the form doesn't have, made up keys.
        ->set('translations.ka.'.Segment::keyOf('internal_code'), 'Hacked')
        ->set('translations.ka.made_up', 'Hacked');

    $editor->save()->assertSaved();

    expect($wine->fresh()->getTranslations('ka'))->toBe(['name' => 'საფერავი']);
})->with('presentations');

it('refuses a locale injected into the editor’s state', function (string $presentation, Closure $editor): void {
    $this->signIn(['translator_locales' => ['ka']]);
    configureEditor($editor);

    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi']]);
    $editor = openEditor($presentation, $wine, 'ka');

    // Copy Georgian's loaded entry to German, which the user may not translate.
    $editor->set('loaded.de', $editor->state('loaded.ka'))
        ->set('translations.de', [Segment::keyOf('name') => 'Hacked']);

    $editor->save()->livewire->assertForbidden();

    expect($wine->fresh()->getTranslatedLocales())->toBe(['en']);
})->with('presentations');

it('reports when nothing changed', function (string $presentation, Closure $editor): void {
    configureEditor($editor);

    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi'], 'ka' => ['name' => 'საფერავი']]);

    openEditor($presentation, $wine, 'ka')
        ->fill('ka', ['name' => " საფერავი \n"])
        ->save()
        ->assertSaved();

    Notification::assertNotified(__('tipi-filament-translations::editor.notifications.unchanged'));
})->with('presentations');

it('copies the reference text with its tags into the translation', function (): void {
    configureEditor(fn (TranslationEditor $e) => $e->page()->comparison());

    $wine = makeRecord(Wine::class, ['en' => ['description' => '<p>Very <strong>dry</strong>.</p>']]);

    $html = openEditor('page', $wine, 'ka')->html();

    // The copy button sets the field to the source text, tags included.
    expect($html)->toContain("\$wire.set('data.translations.ka.".Segment::keyOf('description/0~0')."', 'Very \\u003Cb1\\u003Edry\\u003C\\/b1\\u003E.')");
});

it('opens the target and reference locales from the query string, and ignores unknown ones', function (): void {
    configureEditor(fn (TranslationEditor $e) => $e->page()->comparison());

    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi'], 'de' => ['name' => 'Saperawi']]);

    $editor = openEditor('page', $wine, 'ka', ['reference' => 'de']);

    expect($editor->state('locale'))->toBe('ka')->and($editor->state('reference'))->toBe('de')
        ->and($editor->livewire->get('activeLocale'))->toBe('ka')
        ->and($editor->livewire->get('referenceLocale'))->toBe('de');

    $editor = openEditor('page', $wine, 'xx', ['reference' => 'yy']);

    expect($editor->state('locale'))->toBeIn(['ka', 'de', 'ar'])
        ->and($editor->state('reference'))->toBe('en');
});

it('deletes a translation from the editor', function (string $presentation, Closure $editor): void {
    configureEditor($editor);

    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi'], 'ka' => ['name' => 'საფერავი']]);

    $editor = openEditor($presentation, $wine, 'ka');
    $editor->livewire->callAction(TestAction::make('deleteTranslation')->schemaComponent('tipi-translation-panel-ka', schema: $presentation === 'page' ? 'form' : 'mountedActionSchema0'));

    expect($wine->fresh()->getTranslatedLocales())->toBe(['en'])
        ->and($editor->text('ka', 'name'))->toBeNull();
})->with([
    'page' => ['page', fn (TranslationEditor $e) => $e->page()],
]);
