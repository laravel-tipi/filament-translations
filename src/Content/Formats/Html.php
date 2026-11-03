<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Content\Formats;

use Tipi\Translations\Filament\Content\Document;
use Tipi\Translations\Filament\Content\Format;

/**
 * HTML, as Filament's RichEditor writes it (and HTML in general).
 *
 * Parsed with PHP's HTML5 parser. Text sits in runs: the inline content
 * of a block (paragraph, heading, list item, table cell, …) between its
 * block children. A translation replaces the runs of a copy of the source
 * tree and nothing else, so every block, attribute and embed stays the
 * source's. Inline elements become tags; elements without text, code,
 * media and editor nodes (data-type) are kept whole as void tags.
 * Images' alt texts are segments of their own.
 *
 * @internal
 */
final class Html implements Format
{
    public function read(mixed $value): ?Document
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $document = new HtmlDocument($value);

        return $document->isEmpty() ? null : $document;
    }
}
