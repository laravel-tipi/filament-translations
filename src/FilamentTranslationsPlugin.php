<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Panel-wide defaults for translation editors. Optional: without it,
 * editors use the package defaults.
 *
 *     FilamentTranslationsPlugin::make()
 *         ->editor(fn (TranslationEditor $editor) => $editor->page()->comparison()->context('web'));
 *
 * Resources and actions can still override any setting.
 */
final class FilamentTranslationsPlugin implements Plugin
{
    public const string ID = 'tipi-translations';

    private ?Closure $editor = null;

    public static function make(): self
    {
        return app(self::class);
    }

    public function getId(): string
    {
        return self::ID;
    }

    /**
     * Configure every translation editor of the panel.
     *
     * @param  Closure(TranslationEditor): (TranslationEditor|void)  $configure
     */
    public function editor(Closure $configure): self
    {
        $this->editor = $configure;

        return $this;
    }

    /**
     * @internal Applied by TranslationEditor::make().
     */
    public function configureEditor(TranslationEditor $editor): TranslationEditor
    {
        if ($this->editor === null) {
            return $editor;
        }

        $configured = ($this->editor)($editor);

        return $configured instanceof TranslationEditor ? $configured : $editor;
    }

    public function register(Panel $panel): void
    {
        //
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
