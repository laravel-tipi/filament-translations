<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use Tipi\Translations\Filament\Actions\TranslateAction;
use Tipi\Translations\Filament\Editor\RecordEditor;
use Tipi\Translations\Filament\Enums\EditorLayout;
use Tipi\Translations\Filament\Enums\EditorPresentation;
use Tipi\Translations\Filament\Enums\LocaleNavigation;
use Tipi\Translations\Filament\Tests\Fixtures\EditorSettings;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Wine;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\WineResource;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\Wines\EditWine;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\Wines\ListWines;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\Wines\TranslateWine;
use Tipi\Translations\Filament\TranslationEditor;

beforeEach(function (): void {
    $this->signIn();
});

it('defaults to a comparison modal on the context locales', function (): void {
    $editor = TranslationEditor::make();

    expect($editor->getPresentation())->toBe(EditorPresentation::Modal)
        ->and($editor->getLayout())->toBe(EditorLayout::Comparison)
        ->and($editor->getNavigation(5))->toBe(LocaleNavigation::Tabs)
        ->and($editor->getNavigation(6))->toBe(LocaleNavigation::Select);

    $wine = makeRecord(Wine::class);

    expect((new RecordEditor(WineResource::getTranslationEditor(), $wine))->locales()->keys()->all())->toBe(['en', 'ka', 'de', 'ar']);
});

it('applies panel defaults, then resource overrides, then action overrides', function (): void {
    EditorSettings::$panel = fn (TranslationEditor $e) => $e->page()->localeSelect();

    expect(WineResource::getTranslationEditor()->getPresentation())->toBe(EditorPresentation::Page)
        ->and(WineResource::getTranslationEditor()->getNavigation(2))->toBe(LocaleNavigation::Select);

    EditorSettings::$resource = fn (TranslationEditor $e) => $e->modal();

    expect(WineResource::getTranslationEditor()->getPresentation())->toBe(EditorPresentation::Modal)
        ->and(WineResource::getTranslationEditor()->getLayout())->toBe(EditorLayout::LocaleFocused);

    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi']]);
    $livewire = Livewire::test(EditWine::class, ['record' => $wine->getRouteKey()])->instance();

    $action = TranslateAction::make()
        ->livewire($livewire)
        ->record($wine)
        ->editor(fn (TranslationEditor $editor) => $editor->slideOver());

    expect($action->getEditorConfig()->getPresentation())->toBe(EditorPresentation::SlideOver)
        ->and($action->getEditorConfig()->getResource())->toBe(WineResource::class);
});

it('links to the page or opens the modal from the same action', function (): void {
    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi']]);

    Livewire::test(EditWine::class, ['record' => $wine->getRouteKey()])
        ->mountAction('translate')
        ->assertActionMounted('translate');

    configureEditor(fn (TranslationEditor $e) => $e->page());

    Livewire::test(EditWine::class, ['record' => $wine->getRouteKey()])
        ->assertActionHasUrl('translate', TranslateWine::getUrl(['record' => $wine]));

    Livewire::test(ListWines::class)
        ->assertActionHasUrl(TestAction::make('translate')->table($wine), TranslateWine::getUrl(['record' => $wine]));
});

it('uses the slide-over and modal widths natively', function (): void {
    configureEditor(fn (TranslationEditor $e) => $e->slideOver()->localeTabs());

    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi']]);
    $action = Livewire::test(ListWines::class)
        ->mountAction(TestAction::make('translate')->table($wine))
        ->instance()
        ->getMountedAction();

    expect($action->isModalSlideOver())->toBeTrue()
        ->and($action->getModalWidth()->value)->toBe('4xl')
        ->and($action->isModalClosedByClickingAway())->toBeFalse();
});

it('runs editors with each panel’s defaults', function (): void {
    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi']]);

    Filament::setCurrentPanel(Filament::getPanel('partners'));

    $response = $this->get(Filament::getPanel('partners')->getUrl().'/wines/'.$wine->getKey().'/translations');

    $response->assertOk()
        ->assertSee('fi-tipi-tr-nav-select', escape: false)
        ->assertSee('Saperavi')
        ->assertDontSee('tipi-translation-tab-', escape: false);

    $editor = new RecordEditor(WineResource::getTranslationEditor(), $wine);

    expect($editor->locales()->keys()->all())->toBe(['en', 'de'])
        ->and($editor->config->getPresentation())->toBe(EditorPresentation::Page);
});

it('edits the locales of the content context, not the panel’s', function (): void {
    // The panel's interface locale is Georgian, in the "admin" context.
    App::setLocale('ka');

    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi']]);

    configureEditor(fn (TranslationEditor $e) => $e->page()->localeTabs());

    $editor = openEditor('page', $wine);

    // Locale-focused: opens the content context's default, English.
    expect($editor->state('locale'))->toBe('en');

    configureEditor(fn (TranslationEditor $e) => $e->page()->localeTabs()->context('admin'));

    $record = new RecordEditor(WineResource::getTranslationEditor(), $wine);

    expect($record->locales()->keys()->all())->toBe(['en', 'ka'])
        ->and(openEditor('page', $wine)->state('locale'))->toBe('ka');
});

it('offers inactive locales for drafting when enabled', function (): void {
    configureEditor(fn (TranslationEditor $e) => $e->page()->localeTabs()->inactiveLocales());

    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi']]);

    $editor = openEditor('page', $wine, 'fr');

    expect($editor->state('locale'))->toBe('fr')
        ->and($editor->html())->toContain(__('tipi-filament-translations::editor.panel.inactive'));

    $editor->fill('fr', ['name' => 'Saperavi (fr)'])->save()->assertSaved();

    expect($wine->fresh()->getTranslation('name', 'fr'))->toBe('Saperavi (fr)');
});

it('edits an explicit list of locales', function (): void {
    configureEditor(fn (TranslationEditor $e) => $e->locales(fn (Wine $wine): array => ['ka', 'en', 'fr']));

    $wine = makeRecord(Wine::class);

    // In registry order, inactive French included because it is listed.
    expect((new RecordEditor(WineResource::getTranslationEditor(), $wine))->locales()->keys()->all())->toBe(['en', 'ka', 'fr']);
});

it('rejects forms with fields that are not translatable attributes', function (): void {
    $wine = makeRecord(Wine::class);

    $editor = TranslationEditor::make()->form(fn (Schema $schema): Schema => $schema->components([
        TextInput::make('name'),
        TextInput::make('slug'),
    ]));

    expect(fn () => (new RecordEditor($editor, $wine))->attributes())
        ->toThrow(LogicException::class, 'The translation form field [slug] is not a translatable attribute');
});

it('requires a form', function (): void {
    expect(fn () => TranslationEditor::make()->getForm())->toThrow(LogicException::class);
});

it('treats only edits as unsaved changes on the page', function (): void {
    configureEditor(fn (TranslationEditor $e) => $e->page()->comparison());

    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi', 'description' => '<p>Dry</p>'], 'de' => ['name' => 'Saperawi']]);

    $editor = openEditor('page', $wine, 'ka');
    $isClean = fn (): bool => md5((string) str(json_encode($editor->livewire->get('data'), JSON_UNESCAPED_UNICODE))->replace('\\', '')) === $editor->livewire->get('savedDataHash');

    expect($isClean())->toBeTrue();

    $editor->switchTo('ar')->source('de');

    expect($isClean())->toBeTrue();

    $editor->fill('ka', ['name' => 'საფერავი']);

    expect($isClean())->toBeFalse();

    $editor->save()->assertSaved();

    expect($isClean())->toBeTrue()
        ->and($editor->state('loaded'))->toHaveKey('ar');
});
