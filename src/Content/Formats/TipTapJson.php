<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Content\Formats;

use JsonException;
use Tipi\Translations\Filament\Content\Document;
use Tipi\Translations\Filament\Content\Format;
use Tipi\Translations\Filament\Content\UnsupportedContent;
use Tiptap\Editor;

/**
 * A RichEditor stored as JSON (->json()): converted to HTML with the
 * field's own TipTap extensions, translated as HTML, converted back.
 *
 * @internal
 */
final readonly class TipTapJson implements Format
{
    /**
     * @param  array<string, list<string>>  $customBlocks
     */
    public function __construct(private Editor $editor, private array $customBlocks = []) {}

    public function read(mixed $value): ?Document
    {
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new UnsupportedContent('json');
            }
        }

        if (! is_array($value) || $value === []) {
            return null;
        }

        $document = (new Html($this->customBlocks))->read($this->editor->setContent($value)->getHTML());

        return $document === null ? null : new TipTapDocument($document, $this->editor);
    }
}
