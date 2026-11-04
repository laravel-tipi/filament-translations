<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Tests\Fixtures;

use Filament\Panel;
use Filament\PanelProvider;
use Tipi\Localization\Filament\FilamentLocalizationPlugin;
use Tipi\Translations\Filament\FilamentTranslationsPlugin;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\WineResource;

/**
 * A panel set up the way applications combine the packages: the real
 * Filament Localization plugin runs it in the "admin" context (en, ka;
 * defaulting to ka), while content is published in "web". The plugins
 * are registered before the panel middleware on purpose.
 */
final class TranslatorsPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('translators')
            ->path('translators')
            ->plugins([
                FilamentLocalizationPlugin::make()->context('admin')->management(false),
                FilamentTranslationsPlugin::make(),
            ])
            ->resources([WineResource::class])
            ->middleware(PanelMiddleware::web())
            ->authMiddleware(PanelMiddleware::auth());
    }
}
