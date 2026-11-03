<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;
use Tipi\Translations\Filament\Tests\Fixtures\EditorSettings;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Grape;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Region;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Wine;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\GrapeResource;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\RegionResource;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\WineResource;
use Tipi\Translations\Filament\Tests\Support\Editor;
use Tipi\Translations\Filament\Tests\TestCase;
use Tipi\Translations\Filament\TranslationEditor;

uses(TestCase::class)->in('Feature', 'Unit');

/**
 * The three storages: model, resource, and the attributes of its form.
 *
 * @return array<string, array{class-string<Model>, class-string}>
 */
function storages(): array
{
    return [
        'table storage' => [Wine::class, WineResource::class],
        'json storage' => [Grape::class, GrapeResource::class],
        'shared storage' => [Region::class, RegionResource::class],
    ];
}

/**
 * @return array<string, array{string, Closure(TranslationEditor): TranslationEditor}>
 */
function editorCombinations(): array
{
    return [
        'page + comparison' => ['page', fn (TranslationEditor $e) => $e->page()->comparison()],
        'modal + comparison' => ['modal', fn (TranslationEditor $e) => $e->modal()->comparison()],
        'slide-over + comparison' => ['modal', fn (TranslationEditor $e) => $e->slideOver()->comparison()],
        'page + locale tabs' => ['page', fn (TranslationEditor $e) => $e->page()->localeTabs()],
        'modal + locale tabs' => ['modal', fn (TranslationEditor $e) => $e->modal()->localeTabs()],
        'page + locale select' => ['page', fn (TranslationEditor $e) => $e->page()->localeSelect()],
        'modal + locale select' => ['modal', fn (TranslationEditor $e) => $e->modal()->localeSelect()],
    ];
}

/**
 * A translatable record of the model, owned by the signed-in user.
 *
 * @param  class-string<Model>  $model
 * @param  array<string, array<string, mixed>>  $translations
 */
function makeRecord(string $model, array $translations = []): Model
{
    $record = match ($model) {
        Wine::class => new Wine(['slug' => 'saperavi']),
        Region::class => new Region(['owner_id' => auth()->id()]),
        default => new $model,
    };

    $record->fillTranslations($translations)->save();

    return $record->fresh();
}

/**
 * Open the record's editor as the given presentation: the resource page,
 * or the table's translate action in a modal.
 *
 * @param  array<string, string>  $query
 */
function openEditor(string $presentation, Model $record, ?string $locale = null, array $query = []): Editor
{
    $resource = match ($record::class) {
        Wine::class => WineResource::class,
        Grape::class => GrapeResource::class,
        default => RegionResource::class,
    };

    if ($presentation === 'page') {
        $page = $resource::getPages()['translations']->getPage();

        $livewire = Livewire::withQueryParams(array_filter([...$query, 'locale' => $locale]))
            ->test($page, ['record' => $record->getRouteKey()]);

        return new Editor($livewire, 'data', page: true);
    }

    $list = $resource::getPages()['index']->getPage();

    $livewire = Livewire::test($list)->mountAction(
        TestAction::make('translate')->table($record)->arguments(array_filter(['locale' => $locale])),
    );

    return new Editor($livewire, 'mountedActions.0.data', page: false);
}

function configureEditor(Closure $configure): void
{
    EditorSettings::$resource = $configure;
}
