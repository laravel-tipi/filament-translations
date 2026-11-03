<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Content\Nodes;

use Tipi\Translations\Filament\Content\Alignment;

/**
 * A list of items (Repeater, Builder). The translation has the source's
 * items, in the source's order, with the source's non-text data.
 *
 * Items are identified by their id field when they have one, so items
 * added, removed or moved in the source keep their translations. Items
 * without one are matched by position, and only when the item's data
 * other than its texts is unchanged; reordering items whose data only
 * differs in their texts can't be told apart, so give reorderable items
 * an id.
 *
 * @internal
 */
final readonly class ItemsNode implements Node
{
    /**
     * @param  Node|null  $item  every item's shape (Repeater)
     * @param  array<string, Node>  $blocks  shape by block type (Builder: items are ['type' => …, 'data' => […]])
     * @param  array<string, string>  $blockLabels
     */
    public function __construct(
        public ?Node $item = null,
        public array $blocks = [],
        public array $blockLabels = [],
        public ?string $idKey = 'id',
    ) {}

    public function segments(string $attribute, string $path, array $context, mixed $value): array
    {
        $segments = [];
        $position = 0;

        foreach ($this->items($value) as $identity => $item) {
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

        foreach ($this->items($value) as $identity => $item) {
            $structure[] = [$identity, $this->itemSkeleton($item)];
        }

        return md5(json_encode($structure, JSON_THROW_ON_ERROR));
    }

    public function align(string $path, mixed $source, mixed $target, Alignment $alignment): void
    {
        $targets = $this->items($target);

        foreach ($this->items($source) as $identity => $item) {
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

        foreach ($this->items($source) as $identity => $item) {
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
     * Items by identity: their id, or their position ("#1").
     *
     * @return array<string, mixed>
     */
    private function items(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];

        foreach (array_values($value) as $index => $item) {
            $data = $this->blocks === [] ? $item : (is_array($item) ? ($item['data'] ?? null) : null);
            $id = $this->idKey !== null && is_array($data) ? ($data[$this->idKey] ?? null) : null;

            $identity = (is_string($id) && $id !== '') || is_int($id) ? rawurlencode((string) $id) : '#'.($index + 1);

            // Two items with one id: identify the second by position.
            $items[isset($items[$identity]) ? '#'.($index + 1) : $identity] = $item;
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
            return [null, $item, null];
        }

        return [$this->blocks[$type], $item['data'] ?? null, $this->blockLabels[$type] ?? $type];
    }

    private function itemSkeleton(mixed $item): string
    {
        [$node, $data] = $this->shape($item);

        $type = $this->blocks === [] || ! is_array($item) ? null : ($item['type'] ?? null);

        return md5(json_encode([$type, $node === null ? $item : $node->skeleton($data)], JSON_THROW_ON_ERROR));
    }

    private function orphan(mixed $item, Alignment $alignment): void
    {
        [$node, $data] = $this->shape($item);

        foreach ($node?->segments('', '', [], $data) ?? [] as $segment) {
            $alignment->orphans[] = $segment->text;
        }
    }
}
