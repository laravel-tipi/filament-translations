<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Tests\Fixtures\Resources;

use Filament\Actions\EditAction;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
 * Table storage with states: plain text, rich text and Markdown, a
 * repeater with item ids and a builder. "internal_code" is translatable
 * but not in the form.
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
                MarkdownEditor::make('tasting')->label('Tasting notes'),
                Repeater::make('notes')
                    ->label('Notes')
                    ->schema([
                        Hidden::make('id'),
                        TextInput::make('note')->required(),
                        TextInput::make('score')->numeric(),
                    ])
                    ->defaultItems(0),
            ]),
            Builder::make('content')
                ->label('Content')
                ->blocks([
                    Block::make('heading')->label('Heading')->schema([
                        TextInput::make('text'),
                        Select::make('level')->options(['h2' => 'H2', 'h3' => 'H3']),
                    ]),
                    Block::make('quote')->label('Quote')->schema([
                        Textarea::make('text'),
                        TextInput::make('author'),
                    ]),
                    Block::make('image')->label('Image')->schema([
                        TextInput::make('url')->url(),
                    ]),
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
