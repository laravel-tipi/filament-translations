<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament;

use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\ServiceProvider;
use Tipi\Translations\Filament\Support\ContentContext;

final class FilamentTranslationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ContentContext::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'tipi-filament-translations');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'tipi-filament-translations');

        FilamentAsset::register([
            Css::make('tipi-filament-translations', __DIR__.'/../resources/css/translations.css'),
        ], package: 'laravel-tipi/filament-translations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/tipi-filament-translations'),
            ], 'tipi-filament-translations-views');

            $this->publishes([
                __DIR__.'/../resources/lang' => $this->app->langPath('vendor/tipi-filament-translations'),
            ], 'tipi-filament-translations-translations');
        }
    }
}
