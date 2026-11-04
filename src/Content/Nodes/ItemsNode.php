<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Content\Nodes;

use Tipi\Translations\Filament\Content\Alignment;
use Tipi\Translations\Filament\Content\UnsupportedContent;

/**
 * A list of items (Repeater, Builder). The translation has the source's
 * items, in the source's order, with the source's non-text data.
 *
 * Items are identified by their id field, so translations follow their
 * items when the source adds, removes or reorders them. Without ids,
 * nothing tells a moved item from a changed one, so lists that can change
 * must have them: an item without an id, or two items with one id, make
 * the list untranslatable instead of risking a wrong match. Only fixed
 * lists (no adding, deleting or reordering) are matched by position.
 *
 * @internal
 */
final readonly class ItemsNode implements Node
{
    /**
     * @param  Node|null  $item  every item's shape (Repeater)
     * @param  array<string, Node>  $blocks  shape by block type (Builder: items are ['type' => …, 'data' => […]])
     * @param  array<string, string>  $blockLabels
     * @param  bool  $positional  a fixed list, matched by position
     */
    public function __construct(
        public ?Node $item = null,
        public array $blocks = [],
        public array $blockLabels = [],
        public ?string $idKey = 'id',
        public bool $positional = false,
    ) {}

    public function segments(string $attribute, string $path, array $context, mixed $value): array
    {
        $segments = [];
        $position = 0;

        foreach ($this->items($value, strict: true) as $identity => $item) {
            $position++;
            [$node, $data, $label] = $this->shape($item);

            if ($node !== null) {
                $segments = [...$segments, ...$node->segments($attribute, Alignment::join($path, $identity), [...$context, $label === null ? (string) $position : "{$position} · {$label}"], $data)];
            }
        }

        return $segments;
    }

    public function skeleton(mixed $value): string
    {
        $structure = [];

        foreach ($this->items($value, strict: false) as $identity => $item) {
            $structure[] = [$identity, $this->itemSkeleton($item)];
        }

        return md5(json_encode($structure, JSON_THROW_ON_ERROR));
    }

    public function anchors(string $path, mixed $value): array
    {
        // Positions are paths: the list itself is an anchor.
        $anchors = $this->positional ? [$path.'#' => $this->skeleton($value)] : [];

        foreach ($this->items($value, strict: false) as $identity => $item) {
            [$node, $data] = $this->shape($item);
            $itemPath = Alignment::join($path, $identity);

            if ($this->blocks !== []) {
                $anchors[$itemPath.'@'] = is_array($item) ? (string) ($item['type'] ?? '') : '';
            }

            $anchors += $node?->anchors($itemPath, $data) ?? [];
        }

        return $anchors;
    }

    public function align(string $path, mixed $source, mixed $target, Alignment $alignment): void
    {
        $unmatched = [];
        $targets = $this->items($target, strict: false, skipped: $unmatched);

        // Stored items without a usable id: shown for reference only.
        foreach ($unmatched as $candidate) {
            $this->orphan($candidate, $alignment);
        }

        foreach ($this->items($source, strict: false) as $identity => $item) {
            $candidate = $targets[$identity] ?? null;
            unset($targets[$identity]);

            if ($candidate === null) {
                continue;
            }

            if ($this->itemSkeleton($candidate) !== $this->itemSkeleton($item)) {
                $this->orphan($candidate, $alignment);

                continue;
            }

            [$node, $data] = $this->shape($item);
            $node?->align(Alignment::join($path, $identity), $data, $this->shape($candidate)[1], $alignment);
        }

        foreach ($targets as $candidate) {
            $this->orphan($candidate, $alignment);
        }
    }

    public function rebuild(string $path, mixed $source, array $texts): mixed
    {
        if (! is_array($source)) {
            return $source;
        }

        $value = [];

        foreach ($this->items($source, strict: true) as $identity => $item) {
            [$node, $data] = $this->shape($item);

            if ($node === null) {
                $value[] = $item;

                continue;
            }

            $translated = $node->rebuild(Alignment::join($path, $identity), $data, $texts);
            $value[] = $this->blocks === [] ? $translated : [...$item, 'data' => $translated];
        }

        return $value;
    }

    /**
     * Items by identity: their id, or for fixed lists their position
     * ("#1"). Strictly (the source), an item without an id or a repeated
     * id is an error; otherwise (stored translations) such items are left
     * out, so they never match.
     *
     * @param  list<mixed>  $skipped  items left out, when not strict
     * @return array<string, mixed>
     *
     * @throws UnsupportedContent
     */
    private function items(mixed $value, bool $strict, array &$skipped = []): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];

        foreach (array_values($value) as $index => $item) {
            if ($this->positional) {
                $items['#'.($index + 1)] = $item;

                continue;
            }

            [$node, $data] = $this->shape($item);
            $id = is_array($data) && $this->idKey !== null ? ($data[$this->idKey] ?? null) : null;
            $id = (is_string($id) && $id !== '') || is_int($id) ? rawurlencode((string) $id) : null;

            // Blocks of undeclared types have nothing to translate: their
            // identity doesn't matter.
            if ($node === null && $this->blocks !== []) {
                $items[$id ?? '~'.($index + 1)] ??= $item;

                continue;
            }

            if ($id === null || isset($items[$id])) {
                if ($strict) {
                    throw new UnsupportedContent($id === null ? 'item_id_missing' : 'item_id_duplicate', [
                        'key' => (string) $this->idKey,
                        'position' => (string) ($index + 1),
                        'id' => rawurldecode((string) $id),
                    ]);
                }

                $skipped[] = $item;

                continue;
            }

            $items[$id] = $item;
        }

        return $items;
    }

    /**
     * @return array{0: Node|null, 1: mixed, 2: string|null}
     */
    private function shape(mixed $item): array
    {
        if ($this->blocks === []) {
            return [$this->item, $item, null];
        }

        $type = is_array($item) ? ($item['type'] ?? null) : null;

        if (! is_string($type) || ! isset($this->blocks[$type])) {
            return [null, is_array($item) ? ($item['data'] ?? null) : null, null];
        }

        return [$this->blocks[$type], $item['data'] ?? null, $this->blockLabels[$type] ?? $type];
    }

    private function itemSkeleton(mixed $item): string
    {
        [$node, $data] = $this->shape($item);

        $type = $this->blocks === [] || ! is_array($item) ? null : ($item['type'] ?? null);

        return md5(json_encode([$type, $node === null ? self::sorted($item) : $node->skeleton($data)], JSON_THROW_ON_ERROR));
    }

    /**
     * Keys sorted at every level: databases may reorder JSON keys.
     */
    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sorted(...), $value);
    }

    private function orphan(mixed $item, Alignment $alignment): void
    {
        [$node, $data] = $this->shape($item);

        try {
            foreach ($node?->segments('', '', [], $data) ?? [] as $segment) {
                $alignment->orphans[] = $segment->text;
            }
        } catch (UnsupportedContent) {
            // Nested items without ids: nothing to show.
        }
    }
}
