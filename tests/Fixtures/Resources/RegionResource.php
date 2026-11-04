<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Tests\Fixtures\Resources;

use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Tipi\Translations\Filament\Actions\TranslateAction;
use Tipi\Translations\Filament\Resources\Concerns\Translatable;
use Tipi\Translations\Filament\Tables\Columns\TranslationsColumn;
use Tipi\Translations\Filament\Tables\Filters\TranslationStatusFilter;
use Tipi\Translations\Filament\Tests\Fixtures\EditorSettings;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Region;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\Regions\ListRegions;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\Regions\TranslateRegion;
use Tipi\Translations\Filament\TranslationEditor;

/**
 * Shared storage with states, scoped to the signed-in owner like a tenant.
 */
final class RegionResource extends Resource
{
    use Translatable;

    protected static ?string $model = Region::class;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('owner_id', Filament::auth()->id());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('id'), TranslationsColumn::make()])
            ->filters([TranslationStatusFilter::make()])
            ->recordActions([TranslateAction::make()]);
    }

    public static function translationForm(Schema $schema): Schema
    {
        return $schema->components([
            Group::make([
                TextInput::make('name')->required(),
                Textarea::make('description'),
            ])->columns(2),
            // Simple items can't have ids: a fixed list.
            Repeater::make('notes')->simple(TextInput::make('note'))->defaultItems(0)
                ->addable(false)->deletable(false)->reorderable(false),
        ]);
    }

    public static function translationEditor(TranslationEditor $editor): TranslationEditor
    {
        return EditorSettings::apply(EditorSettings::$resource, $editor);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRegions::route('/'),
            'translations' => TranslateRegion::route('/{record}/translations'),
        ];
    }
}
