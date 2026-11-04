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
 * Alt and title texts are segments of their own; so are the declared
 * config texts of custom blocks, and a custom block with undeclared text
 * makes the document untranslatable.
 *
 * @internal
 */
final readonly class Html implements Format
{
    /**
     * @param  array<string, list<string>>  $customBlocks  custom block id => its translatable config keys
     */
    public function __construct(private array $customBlocks = []) {}

    public function read(mixed $value): ?Document
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $document = new HtmlDocument($value, $this->customBlocks);

        return $document->isEmpty() ? null : $document;
    }
}
