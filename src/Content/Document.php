<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Content;

/**
 * A parsed rich document (Markdown or HTML).
 *
 * @internal
 */
interface Document
{
    /**
     * Every run, with or without text, by path. Paths depend only on the
     * document's structure, never on its text.
     *
     * @return array<string, Run>
     */
    public function runs(): array;

    /**
     * The structure without text: equal for documents that only differ in
     * their text and inline formatting.
     */
    public function skeleton(): string;

    /**
     * This document's structure with new text. Runs without a tree (no
     * translation) keep their void tags only.
     *
     * @param  array<string, list<string|Tag>|null>  $trees  path => tree named like this document's tags
     */
    public function rebuild(array $trees): mixed;
}
