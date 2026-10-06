<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament;

use Illuminate\Support\ServiceProvider;

final class FilamentTranslationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadViewsFrom(
            __DIR__ . '/../resources/views',
            'tipi-filament-translations',
        );
    }
}