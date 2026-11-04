<?php

declare(strict_types=1);

use Tipi\Translations\Filament\Tests\Fixtures\Models\Wine;
use Tipi\Translations\Filament\TranslationEditor;

/*
 | The "translators" panel combines both Filament plugins: Filament
 | Localization runs it in the "admin" context (en, ka; default ka), while
 | the editor translates content published in "web" (every active locale).
 | Real requests, through the plugins' own middleware.
 */

beforeEach(function (): void {
    $this->signIn();
    configureEditor(fn (TranslationEditor $e) => $e->page()->localeTabs());

    $this->wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi']]);
    $this->url = '/translators/wines/'.$this->wine->getKey().'/translations';
});

it('serves the panel in its context while editing the content context’s locales', function (): void {
    $response = $this->withHeader('Accept-Language', 'fr')->get($this->url)->assertOk();

    // The interface: Georgian, the "admin" context default.
    expect($response->getContent())->toMatch('/<html\s+lang="ka"/');

    $response
        // The switcher offers the panel context's locales…
        ->assertSee('wire:key="tipi-locale-en"', escape: false)
        ->assertDontSee('wire:key="tipi-locale-ar"', escape: false)
        // …the editor the content context's.
        ->assertSee('tipi-translation-tab-ar', escape: false)
        ->assertSee('tipi-translation-tab-de', escape: false);
});

it('changes the interface language without changing what is translated', function (): void {
    $response = $this->withCookie('locale_admin', 'en')->get($this->url)->assertOk();

    expect($response->getContent())->toMatch('/<html\s+lang="en"/');

    $response->assertSee('tipi-translation-tab-ka', escape: false)
        ->assertSee('tipi-translation-tab-ar', escape: false);
});

it('reports fallback as readers of the content context see it, not the panel’s', function (): void {
    // The panel's context ("admin") would fall back to Georgian first.
    $this->wine->setTranslation('name', 'ka', 'საფერავი')->save();

    $this->withHeader('Accept-Language', 'fr')->get($this->url.'?locale=de')
        ->assertOk()
        ->assertSee(__('tipi-filament-translations::editor.attribute.fallback', ['locale' => 'English']))
        ->assertDontSee(__('tipi-filament-translations::editor.attribute.fallback', ['locale' => 'Georgian']));
});
