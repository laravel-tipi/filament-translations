<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Content\Nodes;

use Tipi\Translations\Filament\Content\Alignment;
use Tipi\Translations\Filament\Content\Segment;

/**
 * Plain text (TextInput, Textarea): one segment, taken literally.
 *
 * @internal
 */
final readonly class TextNode implements Node
{
    /**
     * @param  array{min?: int, max?: int}  $length
     */
    public function __construct(
        public bool $multiline = true,
        public array $length = [],
    ) {}

    public function segments(string $attribute, string $path, array $context, mixed $value): array
    {
        $text = self::text($value);

        return $text === null ? [] : [new Segment($attribute, $path, $context, 'text', $text, multiline: $this->multiline, length: $this->length)];
    }

    public function skeleton(mixed $value): string
    {
        return 'text';
    }

    public function align(string $path, mixed $source, mixed $target, Alignment $alignment): void
    {
        $text = self::text($target);

        if ($text === null) {
            return;
        }

        if (self::text($source) === null) {
            $alignment->orphans[] = $text;

            return;
        }

        $alignment->texts[$path] = $text;
    }

    public function rebuild(string $path, mixed $source, array $texts): mixed
    {
        return self::normalize($texts[$path] ?? null);
    }

    /**
     * Plain text as it is compared and stored: trimmed, with "\n" line
     * breaks; blank is null.
     */
    public static function normalize(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));

        return $text === '' ? null : $text;
    }

    /**
     * The text of a value: strings and numbers; anything else has none.
     */
    private static function text(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return is_string($value) ? self::normalize($value) : null;
    }
}
