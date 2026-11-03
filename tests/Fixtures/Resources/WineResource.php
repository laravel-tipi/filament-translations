<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Tests\Fixtures\Resources;

use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Tipi\Support\Locale;
use Tipi\Translations\Filament\Actions\TranslateAction;
use Tipi\Translations\Filament\Resources\Concerns\Translatable;
use Tipi\Translations\Filament\Tables\Columns\TranslationsColumn;
use Tipi\Translations\Filament\Tables\Filters\TranslationStatusFilter;
use Tipi\Translations\Filament\Tests\Fixtures\EditorSettings;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Wine;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\Wines\EditWine;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\Wines\ListWines;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\Wines\TranslateWine;
use Tipi\Translations\Filament\TranslationEditor;

/**
 * Table storage with states: a text input, a rich editor and a repeater in
 * a section. "internal_code" is translatable but not in the form.
 */
final class WineResource extends Resource
{
    use Translatable;

    protected static ?string $model = Wine::class;

    protected static ?string $recordTitleAttribute = 'slug';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('slug')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('slug')->searchable()->sortable(),
                TranslationsColumn::make(),
            ])
            ->filters([TranslationStatusFilter::make()])
            ->recordActions([TranslateAction::make(), EditAction::make()]);
    }

    public static function translationForm(Schema $schema, ?Locale $locale = null): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Name')
                ->required()
                // A locale-specific rule: German names are short.
                ->maxLength($locale?->code === 'de' ? 20 : 255),
            RichEditor::make('description')->label('Description'),
            Section::make('Tasting')->schema([
                Repeater::make('notes')
                    ->label('Notes')
                    ->schema([TextInput::make('note')->required()])
                    ->defaultItems(0),
            ]),
        ]);
    }

    public static function translationEditor(TranslationEditor $editor): TranslationEditor
    {
        return EditorSettings::apply(EditorSettings::$resource, $editor);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWines::route('/'),
            'edit' => EditWine::route('/{record}/edit'),
            'translations' => TranslateWine::route('/{record}/translations'),
        ];
    }
}
