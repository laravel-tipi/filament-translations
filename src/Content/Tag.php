<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Content;

/**
 * An inline tag of a segment: formatting or a link around text (a pair,
 * written <b1>…</b1>), or content kept from the source as is (void,
 * written <img2/>), such as an image, inline code or a merge tag.
 *
 * @internal
 */
final readonly class Tag
{
    /**
     * @param  list<string|Tag>  $children
     */
    public function __construct(
        public string $name,
        public array $children = [],
        public bool $void = false,
    ) {}

    /**
     * @param  list<string|Tag>  $children
     */
    public function with(array $children): self
    {
        return new self($this->name, $children, $this->void);
    }
}
