<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Tests\Fixtures;

use Filament\Panel;
use Filament\PanelProvider;
use Tipi\Localization\Http\Middleware\UseLocaleContext;
use Tipi\Translations\Filament\FilamentTranslationsPlugin;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\GrapeResource;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\RegionResource;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\WineResource;
use Tipi\Translations\Filament\TranslationEditor;

/**
 * Runs in the "admin" locale context (en, ka; defaulting to ka), while
 * content is published in "web".
 */
final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->resources([WineResource::class, GrapeResource::class, RegionResource::class])
            ->middleware([...PanelMiddleware::web(), UseLocaleContext::with('admin')])
            ->persistentMiddleware([UseLocaleContext::class])
            ->authMiddleware(PanelMiddleware::auth())
            ->unsavedChangesAlerts()
            ->plugin(
                FilamentTranslationsPlugin::make()
                    ->editor(fn (TranslationEditor $editor): TranslationEditor => EditorSettings::apply(EditorSettings::$panel, $editor)),
            );
    }
}
