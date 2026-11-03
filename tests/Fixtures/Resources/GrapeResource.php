<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Tests\Fixtures\Resources;

use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Tipi\Translations\Filament\Actions\TranslateAction;
use Tipi\Translations\Filament\Resources\Concerns\Translatable;
use Tipi\Translations\Filament\Tables\Columns\TranslationsColumn;
use Tipi\Translations\Filament\Tables\Filters\TranslationStatusFilter;
use Tipi\Translations\Filament\Tests\Fixtures\EditorSettings;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Grape;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\Grapes\ListGrapes;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\Grapes\TranslateGrape;
use Tipi\Translations\Filament\TranslationEditor;

/**
 * JSON storage without states and without a policy.
 */
final class GrapeResource extends Resource
{
    use Translatable;

    protected static ?string $model = Grape::class;

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
            TextInput::make('name')->required(),
            MarkdownEditor::make('description'),
            // Items without ids: matched by position.
            Repeater::make('notes')->schema([TextInput::make('label')])->defaultItems(0),
        ]);
    }

    public static function translationEditor(TranslationEditor $editor): TranslationEditor
    {
        return EditorSettings::apply(EditorSettings::$resource, $editor);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGrapes::route('/'),
            'translations' => TranslateGrape::route('/{record}/translations'),
        ];
    }
}
