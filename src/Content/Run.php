<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Content;

/**
 * One run of inline content in a document: the text of a paragraph,
 * heading, list item or table cell, or an image description.
 *
 * @internal
 */
final readonly class Run
{
    /**
     * @param  string  $role  what the run is: paragraph, heading, list_item, quote, table_cell, caption, alt
     * @param  list<string|Tag>  $nodes  canonical
     * @param  array<string, TagInfo>  $tags
     */
    public function __construct(
        public string $path,
        public string $role,
        public array $nodes,
        public array $tags = [],
        public bool $multiline = true,
    ) {}

    public function hasText(): bool
    {
        return Inline::hasText($this->nodes);
    }

    public function text(): string
    {
        return Inline::render($this->nodes);
    }

    /**
     * @return array<string, bool> name => whether it is void
     */
    public function tagForms(): array
    {
        return array_map(fn (TagInfo $tag): bool => $tag->void, $this->tags);
    }

    /**
     * The tree with the tags named like the matching tags of the source
     * run: the same kind and target first, then the same kind. Null when a
     * tag has no match, so the text can't be read with the source's tags.
     *
     * @return list<string|Tag>|null
     */
    public function namedLike(self $source): ?array
    {
        $names = [];
        $free = $source->tags;

        foreach ([true, false] as $exact) {
            foreach ($this->tags as $name => $tag) {
                if (isset($names[$name])) {
                    continue;
                }

                foreach ($free as $candidate => $sourceTag) {
                    if ($sourceTag->void === $tag->void && $sourceTag->kind === $tag->kind && (! $exact || $sourceTag->signature === $tag->signature)) {
                        $names[$name] = $candidate;
                        unset($free[$candidate]);

                        break;
                    }
                }
            }
        }

        if (count($names) !== count($this->tags)) {
            return null;
        }

        return self::rename($this->nodes, $names);
    }

    /**
     * @param  list<string|Tag>  $nodes
     * @param  array<string, string>  $names
     * @return list<string|Tag>
     */
    private static function rename(array $nodes, array $names): array
    {
        return array_map(
            fn (string|Tag $node): string|Tag => is_string($node)
                ? $node
                : new Tag($names[$node->name], self::rename($node->children, $names), $node->void),
            $nodes,
        );
    }
}
