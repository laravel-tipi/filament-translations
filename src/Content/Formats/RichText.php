<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Content\Formats;

use Closure;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Throwable;
use Tipi\Translations\Filament\Content\Document;
use Tipi\Translations\Filament\Content\Format;
use Tiptap\Editor;

/**
 * A RichEditor's value: HTML, or TipTap JSON when the editor stores JSON.
 * Told apart by the value, so it works whatever the field's setup.
 *
 * @internal
 */
final class RichText implements Format
{
    private ?Editor $editor = null;

    /**
     * @param  (Closure(): Editor)|null  $editor  the field's TipTap editor, with its extensions
     */
    public function __construct(private readonly ?Closure $editorUsing = null) {}

    public function read(mixed $value): ?Document
    {
        if (self::isJson($value)) {
            return (new TipTapJson($this->editor()))->read($value);
        }

        return (new Html)->read($value);
    }

    private static function isJson(mixed $value): bool
    {
        if (is_array($value)) {
            return true;
        }

        if (! is_string($value) || ! str_starts_with(ltrim($value), '{')) {
            return false;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) && ($decoded['type'] ?? null) === 'doc';
    }

    private function editor(): Editor
    {
        if ($this->editor !== null) {
            return $this->editor;
        }

        try {
            $editor = $this->editorUsing === null ? null : ($this->editorUsing)();
        } catch (Throwable) {
            $editor = null;
        }

        return $this->editor = $editor ?? RichContentRenderer::make()->getEditor();
    }
}
