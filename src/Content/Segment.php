<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Content;

/**
 * One translatable text of a record: a plain text value, a run of a
 * document, an image description, or such a text inside a repeater or
 * builder item.
 *
 * @internal
 */
final readonly class Segment
{
    /**
     * @param  string  $path  within the attribute; depends only on the source's structure
     * @param  list<string>  $context  where it sits: item numbers, field labels
     * @param  string  $role  text, paragraph, heading, list_item, quote, table_cell, caption, alt
     * @param  string  $text  the source text, with tags for rich text
     * @param  array<string, TagInfo>  $tags
     * @param  bool  $rich  whether tags are read from translations
     * @param  array{min?: int, max?: int}  $length
     */
    public function __construct(
        public string $attribute,
        public string $path,
        public array $context,
        public string $role,
        public string $text,
        public array $tags = [],
        public bool $multiline = true,
        public bool $rich = false,
        public array $length = [],
    ) {}

    public function id(): string
    {
        return $this->path === '' ? $this->attribute : $this->attribute.'/'.$this->path;
    }

    /**
     * The key of the segment in the editor's state.
     */
    public function key(): string
    {
        return self::keyOf($this->id());
    }

    public static function keyOf(string $id): string
    {
        return 's'.substr(md5($id), 0, 12);
    }

    /**
     * Fingerprint of the source text, to tell when it changed.
     */
    public function hash(): string
    {
        return substr(md5($this->text), 0, 16);
    }

    /**
     * @return array<string, bool>
     */
    public function tagForms(): array
    {
        return array_map(fn (TagInfo $tag): bool => $tag->void, $this->tags);
    }
}
