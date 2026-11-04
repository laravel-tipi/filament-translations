<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Content\Formats;

use Tipi\Translations\Filament\Content\Document;
use Tiptap\Editor;

/**
 * @internal
 */
final readonly class TipTapDocument implements Document
{
    public function __construct(
        private Document $html,
        private Editor $editor,
    ) {}

    public function runs(): array
    {
        return $this->html->runs();
    }

    public function skeleton(): string
    {
        return $this->html->skeleton();
    }

    public function rebuild(array $trees): mixed
    {
        return $this->editor->setContent((string) $this->html->rebuild($trees))->getDocument();
    }
}
