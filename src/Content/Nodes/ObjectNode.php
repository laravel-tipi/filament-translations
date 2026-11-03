<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Content\Nodes;

use Illuminate\Support\Str;
use Tipi\Translations\Filament\Content\Alignment;

/**
 * Keyed data (a repeater or builder item, a nested schema): declared
 * fields are translated, every other key is the source's. Its skeleton
 * is that other data.
 *
 * @internal
 */
final readonly class ObjectNode implements Node
{
    /**
     * @param  array<string, Node>  $fields
     * @param  array<string, string>  $labels
     */
    public function __construct(
        public array $fields,
        public array $labels = [],
    ) {}

    public function segments(string $attribute, string $path, array $context, mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $segments = [];

        foreach ($this->fields as $name => $node) {
            $segments = [...$segments, ...$node->segments($attribute, Alignment::join($path, (string) $name), [...$context, $this->labels[$name] ?? Str::headline((string) $name)], $value[$name] ?? null)];
        }

        return $segments;
    }

    public function skeleton(mixed $value): string
    {
        if (! is_array($value)) {
            return md5(json_encode($value, JSON_THROW_ON_ERROR));
        }

        // The data other than declared fields; those check their own
        // structure when they are aligned.
        $structure = array_diff_key($value, $this->fields);
        ksort($structure);

        return md5(json_encode($structure, JSON_THROW_ON_ERROR));
    }

    public function align(string $path, mixed $source, mixed $target, Alignment $alignment): void
    {
        if (! is_array($target)) {
            return;
        }

        foreach ($this->fields as $name => $node) {
            $node->align(Alignment::join($path, (string) $name), is_array($source) ? ($source[$name] ?? null) : null, $target[$name] ?? null, $alignment);
        }
    }

    public function rebuild(string $path, mixed $source, array $texts): mixed
    {
        if (! is_array($source)) {
            return $source;
        }

        $value = $source;

        foreach ($this->fields as $name => $node) {
            $translated = $node->rebuild(Alignment::join($path, (string) $name), $source[$name] ?? null, $texts);

            if ($translated !== null || array_key_exists($name, $source)) {
                $value[$name] = $translated;
            }
        }

        return $value;
    }
}
