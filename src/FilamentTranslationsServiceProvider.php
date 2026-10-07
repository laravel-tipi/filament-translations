<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament;

use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Filament\Tables\Table;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Tipi\Translations\Filament\Actions\EditTranslationAction;
use Tipi\Translations\Filament\Actions\TranslateAction;
use Tipi\Translations\Filament\Contracts\HasTranslationSchema;
use Tipi\Translations\Filament\Tables\Columns\TranslationsColumn;

final class FilamentTranslationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->registerTableMacros();

        $this->loadViewsFrom(
            __DIR__.'/../resources/views',
            'tipi-filament-translations',
        );

        FilamentAsset::register([
            Css::make(
                'tipi-translations',
                __DIR__.'/../resources/css/filament-translations.css',
            ),
        ], package: 'laravel-tipi/filament-translations');
    }

    private function registerTableMacros(): void
    {
        Table::macro('translations', function (): Table {
            /** @var Table $this */
            $livewire = $this->getLivewire();

            if (! method_exists($livewire, 'getResource')) {
                throw new LogicException(
                    'Translations can only be configured on a Filament resource table.',
                );
            }

            $resource = $livewire::getResource();

            if (! is_subclass_of($resource, HasTranslationSchema::class)) {
                throw new LogicException(sprintf(
                    'Resource [%s] must implement [%s].',
                    $resource,
                    HasTranslationSchema::class,
                ));
            }

            $schema = $resource::getTranslationSchema();

            $this->pushColumns([
                TranslationsColumn::make('translations'),
            ]);

            $this->pushRecordActions([
                TranslateAction::make()
                    ->targetLocaleCode(
                        fn (array $arguments): ?string => $arguments['locale'] ?? null,
                    )
                    ->translationSchema($schema)
                    ->extraAttributes([
                        'style' => 'display: none !important;',
                        'class' => '!hidden',
                    ]),

                EditTranslationAction::make()
                    ->localeCode(
                        fn (array $arguments): string => $arguments['locale'],
                    )
                    ->translationSchema($schema)
                    ->extraAttributes([
                        'style' => 'display: none !important;',
                        'class' => '!hidden',
                    ]),
            ]);

            return $this;
        });
    }
}
