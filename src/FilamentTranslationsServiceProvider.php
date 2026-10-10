<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament;

use Filament\Forms\Components\Field;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Filament\Tables\Table;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Tipi\Translations\Filament\Actions\EditTranslationAction;
use Tipi\Translations\Filament\Actions\TranslateAction;
use Tipi\Translations\Filament\Components\TranslationsManager;
use Tipi\Translations\Filament\Contracts\HasTranslationSchema;
use Tipi\Translations\Filament\Schemas\Components\Contracts\TranslationField;
use Tipi\Translations\Filament\Schemas\Components\TranslationGroup;
use Tipi\Translations\Filament\Tables\Columns\TranslationsColumn;

final class FilamentTranslationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->registerTranslationRules();
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

    private function registerTranslationRules(): void
    {
        Field::configureUsing(function (Field $field): void {
            $field->rules(function (Field $component, $livewire): array {
                $resource = match (true) {
                    $livewire instanceof TranslationsManager => $livewire->resource,
                    method_exists($livewire, 'getResource') => $livewire::getResource(),
                    default => null,
                };

                if ($resource === null || ! is_subclass_of($resource, HasTranslationSchema::class)) {
                    return [];
                }

                if (! $component instanceof TranslationField) {
                    $parent = $component->getContainer()->getParentComponent();

                    while ($parent !== null && ! $parent instanceof TranslationGroup) {
                        $parent = $parent->getContainer()->getParentComponent();
                    }

                    if ($parent === null) {
                        return [];
                    }
                }

                if (! preg_match('/(?:^|\.)(?:translation|target_translation|source_translation)\.(.+)$/', $component->getStatePath(), $matches)) {
                    return [];
                }

                return $resource::getTranslationRules()[$matches[1]] ?? [];
            });
        });
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
