<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Content\Nodes;

use Tipi\Translations\Filament\Content\Alignment;
use Tipi\Translations\Filament\Content\InvalidTranslation;
use Tipi\Translations\Filament\Content\Segment;
use Tipi\Translations\Filament\Content\UnsupportedContent;

/**
 * The translatable shape of a value: what its segments are, how a
 * translation lines up with the source, and how a translated value is
 * built from the source and the translated texts.
 *
 * @internal
 */
interface Node
{
    /**
     * The segments of a source value that have text.
     *
     * @param  list<string>  $context
     * @return list<Segment>
     *
     * @throws UnsupportedContent
     */
    public function segments(string $attribute, string $path, array $context, mixed $value): array;

    /**
     * The value's structure without its text.
     */
    public function skeleton(mixed $value): string;

    /**
     * What segment paths depend on, by path: the structure of each document
     * (paths inside it are positions), of positional lists, and builder
     * block types. A segment's path keeps its meaning while the anchors
     * above it are unchanged.
     *
     * @return array<string, string>
     */
    public function anchors(string $path, mixed $value): array;

    /**
     * Read the texts of a stored translation into $alignment, at the paths
     * of the source's segments. Texts that don't line up with the source
     * (its structure changed) are orphans.
     */
    public function align(string $path, mixed $source, mixed $target, Alignment $alignment): void;

    /**
     * The source with its texts replaced: $texts maps segment paths to
     * translated texts, null for untranslated ones.
     *
     * @param  array<string, string|null>  $texts
     *
     * @throws InvalidTranslation
     */
    public function rebuild(string $path, mixed $source, array $texts): mixed;
}
