<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Content;

/**
 * Reads a rich text value into a Document.
 *
 * @internal
 */
interface Format
{
    /**
     * Null for a value without content.
     *
     * @throws UnsupportedContent
     */
    public function read(mixed $value): ?Document;
}
