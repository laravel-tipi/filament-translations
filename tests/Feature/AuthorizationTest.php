<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Region;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Wine;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\Regions\ListRegions;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\Regions\TranslateRegion;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\Wines\EditWine;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\Wines\ListWines;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\Wines\TranslateWine;
use Tipi\Translations\Filament\TranslationEditor;

/*
 * WinePolicy::translate() receives the locale; RegionPolicy has no
 * translate ability, so translating follows update().
 */

it('offers translators only their locales', function (): void {
    $this->signIn(['translator_locales' => ['ka', 'de']]);
    configureEditor(fn (TranslationEditor $e) => $e->page()->localeTabs());

    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi']]);

    $editor = openEditor('page', $wine, 'en');

    // English isn't theirs: the editor opens at a locale they may edit.
    expect($editor->state('locale'))->toBe('ka')
        ->and($editor->html())->toContain('tipi-translation-tab-ka')
        ->and($editor->html())->not->toContain('tipi-translation-tab-en');

    $editor->switchTo('en');

    expect($editor->state('locale'))->toBe('ka')
        ->and($editor->state('loaded'))->not->toHaveKey('en');
});

it('rejects a forged locale on save', function (string $presentation): void {
    $this->signIn(['translator_locales' => ['ka']]);
    configureEditor(fn (TranslationEditor $e) => $presentation === 'page' ? $e->page() : $e->modal());

    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi']]);

    $editor = openEditor($presentation, $wine, 'ka')
        ->fill('ka', ['name' => 'საფერავი'])
        ->set('loaded.en', ['stored' => 'forged'])
        ->set('translations.en.name', 'Hacked');

    $editor->save()->livewire->assertForbidden();

    expect($wine->fresh()->getAllTranslations())->toBe(['en' => ['name' => 'Saperavi']]);
})->with(['page', 'modal']);

it('hides the action and forbids the page without any translatable locale', function (): void {
    $this->signIn(['translator_locales' => []]);

    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi']]);

    Livewire::test(ListWines::class)->assertActionHidden(TestAction::make('translate')->table($wine));
    Livewire::test(EditWine::class, ['record' => $wine->getRouteKey()])->assertActionHidden('translate');

    $this->get(TranslateWine::getUrl(['record' => $wine]))->assertForbidden();
});

it('falls back to the resource edit permission without a translate ability', function (): void {
    $this->signIn(['can_edit' => false]);

    $region = makeRecord(Region::class, ['en' => ['name' => 'Kakheti']]);

    Livewire::test(ListRegions::class)->assertActionHidden(TestAction::make('translate')->table($region));
    $this->get(TranslateRegion::getUrl(['record' => $region]))->assertForbidden();
});

it('re-authorizes every request', function (): void {
    $user = $this->signIn();
    configureEditor(fn (TranslationEditor $e) => $e->page());

    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi']]);
    $editor = openEditor('page', $wine, 'ka')->fill('ka', ['name' => 'საფერავი']);

    $user->forceFill(['translator_locales' => []])->save();

    $editor->save()->livewire->assertForbidden();

    expect($wine->fresh()->hasTranslation('name', 'ka'))->toBeFalse();
});

it('hides deleting a translation the policy protects', function (): void {
    $this->signIn();
    configureEditor(fn (TranslationEditor $e) => $e->page()->localeTabs());

    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi'], 'ka' => ['name' => 'საფერავი']]);

    $editor = openEditor('page', $wine, 'en');

    $editor->livewire->assertActionHidden(TestAction::make('deleteTranslation')->schemaComponent('tipi-translation-panel-en', schema: 'form'));

    $editor->switchTo('ka');

    $editor->livewire->assertActionVisible(TestAction::make('deleteTranslation')->schemaComponent('tipi-translation-panel-ka', schema: 'form'));
});

it('keeps records of other owners out of reach', function (): void {
    $this->signIn();
    $own = makeRecord(Region::class, ['en' => ['name' => 'Kakheti']]);

    $other = (new Region(['owner_id' => 999]))->setTranslation('name', 'en', 'Imereti');
    $other->save();

    // The page resolves records through the resource query, like tenancy.
    $this->get(TranslateRegion::getUrl(['record' => $other]))->assertNotFound();
    $this->get(TranslateRegion::getUrl(['record' => $own]))->assertOk();

    // A forged record key can't mount the table action.
    Livewire::test(ListRegions::class)
        ->call('mountAction', 'translate', [], ['table' => true, 'recordKey' => (string) $other->getKey()])
        ->assertSet('mountedActions', []);
});
