<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Resources\Concerns;

use Filament\Schemas\Schema;
use Tipi\Support\Locale;
use Tipi\Translations\Filament\TranslationEditor;

/**
 * Makes a resource's records translatable in Filament.
 *
 *     class WineResource extends Resource
 *     {
 *         use Translatable;
 *
 *         public static function translationForm(Schema $schema): Schema
 *         {
 *             return $schema->components([
 *                 TextInput::make('name')->required(),
 *                 RichEditor::make('description'),
 *             ]);
 *         }
 *     }
 *
 * The form is built once per locale, so it may also accept the locale:
 * translationForm(Schema $schema, ?Locale $locale = null).
 */
trait Translatable
{
    /**
     * The fields of translatable attributes, named after the attributes.
     */
    abstract public static function translationForm(Schema $schema): Schema;

    /**
     * Override the panel's editor settings for this resource.
     */
    public static function translationEditor(TranslationEditor $editor): TranslationEditor
    {
        return $editor;
    }

    public static function getTranslationEditor(): TranslationEditor
    {
        return static::translationEditor(
            TranslationEditor::make()
                ->resource(static::class)
                ->form(fn (Schema $schema, Locale $locale): Schema => static::translationForm($schema, $locale)),
        );
    }
}
