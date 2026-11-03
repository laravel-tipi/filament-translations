<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Tests\Fixtures;

use Closure;
use Tipi\Translations\Filament\TranslationEditor;

/**
 * Per-test editor settings, applied by the panels and resources.
 */
final class EditorSettings
{
    /**
     * @var (Closure(TranslationEditor): TranslationEditor)|null
     */
    public static ?Closure $panel = null;

    /**
     * @var (Closure(TranslationEditor): TranslationEditor)|null
     */
    public static ?Closure $resource = null;

    public static function reset(): void
    {
        self::$panel = null;
        self::$resource = null;
    }

    public static function apply(?Closure $configure, TranslationEditor $editor): TranslationEditor
    {
        return $configure === null ? $editor : ($configure($editor) ?? $editor);
    }
}
