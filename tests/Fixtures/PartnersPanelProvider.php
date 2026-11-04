<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Tests\Fixtures;

use Filament\Panel;
use Filament\PanelProvider;
use Tipi\Translations\Filament\FilamentTranslationsPlugin;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\WineResource;
use Tipi\Translations\Filament\TranslationEditor;

/**
 * A second panel with other editor defaults: page presentation and a
 * locale select, limited to English and German.
 */
final class PartnersPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('partners')
            ->path('partners')
            ->resources([WineResource::class])
            ->middleware(PanelMiddleware::web())
            ->authMiddleware(PanelMiddleware::auth())
            ->plugin(
                FilamentTranslationsPlugin::make()
                    ->editor(fn (TranslationEditor $editor) => $editor->page()->localeSelect()->locales(['en', 'de'])),
            );
    }
}
