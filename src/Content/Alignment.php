<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Content;

/**
 * The texts of a stored translation, lined up with the source's segments.
 *
 * @internal
 */
final class Alignment
{
    /**
     * @var array<string, string> segment path => text
     */
    public array $texts = [];

    /**
     * Texts that no longer line up with the source, for reference.
     *
     * @var list<string>
     */
    public array $orphans = [];

    public static function join(string $path, string $part): string
    {
        return $path === '' ? $part : $path.'/'.$part;
    }
}
