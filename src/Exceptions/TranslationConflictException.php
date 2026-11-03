<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Exceptions;

use RuntimeException;

/**
 * Another save changed locales after the editor loaded them. Nothing was
 * saved, so the editor's changes are not lost and nobody's changes are
 * overwritten.
 */
final class TranslationConflictException extends RuntimeException
{
    /**
     * @param  list<string>  $locales
     */
    public function __construct(
        public readonly array $locales,
    ) {
        parent::__construct(sprintf('The translations [%s] changed after they were loaded.', implode(', ', $locales)));
    }
}
