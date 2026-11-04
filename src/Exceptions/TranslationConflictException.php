<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Exceptions;

use RuntimeException;

/**
 * A save that would overwrite changes made since the editor loaded them,
 * or translate a source that changed meanwhile. Nothing was saved.
 */
final class TranslationConflictException extends RuntimeException
{
    public const string CHANGED = 'changed';

    public const string SOURCE = 'source';

    public const string STRUCTURE = 'structure';

    /**
     * @param  list<array{locale: string, attribute: string, key: string|null, reason: string, theirs: string|null}>  $conflicts
     */
    public function __construct(public readonly array $conflicts)
    {
        parent::__construct('The translation changed since it was loaded.');
    }

    /**
     * @return list<string>
     */
    public function locales(): array
    {
        return array_values(array_unique(array_column($this->conflicts, 'locale')));
    }
}
