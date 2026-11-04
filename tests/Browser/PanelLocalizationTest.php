<?php

declare(strict_types=1);

use Tipi\Translations\Filament\Content\Segment;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Wine;
use Tipi\Translations\Filament\TranslationEditor;

/*
 | Both Filament plugins on one panel ("translators": Filament
 | Localization in the "admin" context, en and ka), in a real browser: the
 | translator picks the interface language with the switcher, translates
 | into a locale of the "web" context, and readers see the result.
 */

beforeEach(function (): void {
    $this->signIn();
});

it('switches the interface language and translates the content context’s locales', function (): void {
    configureEditor(fn (TranslationEditor $e) => $e->page()->localeTabs());
    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi']]);

    // The browser asks for English, which the panel's context supports.
    // (The wait lets Livewire start before the first click.)
    $page = visit('/translators/wines/'.$wine->getKey().'/translations?locale=ar')
        ->assertSee('Translate saperavi')
        ->wait(1);

    expect($page->script('document.documentElement.lang'))->toBe('en');

    // Georgian, chosen with the switcher: a Livewire request, then a reload.
    $page->click('[aria-label="Change language"]')
        ->click('ქართული')
        ->wait(2);

    expect($page->script('document.documentElement.lang'))->toBe('ka');

    // Arabic isn't a panel language, but it is a content locale.
    $page->assertPresent('[wire\:key="tipi-translation-tab-ar"]')
        ->fill('[data-locale="ar"] [data-segment="'.Segment::keyOf('name').'"] textarea', 'سابيرافي')
        ->press('Save translations')
        ->assertSee('Translations saved')
        ->assertNoJavaScriptErrors();

    expect($wine->fresh()->getTranslation('name', 'ar'))->toBe('سابيرافي');

    visit('/site/ar/wines/'.$wine->getKey())->assertSeeIn('#name', 'سابيرافي');
});
