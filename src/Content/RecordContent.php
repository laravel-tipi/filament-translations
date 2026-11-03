<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Content;

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Contracts\TranslatableModel;

/**
 * A record's translatable content: the segments of its source locale and
 * the stored translations lined up with them.
 *
 * The source decides what has to be translated: a segment exists only
 * where the source has text. Values of other locales count only where
 * they line up with the source's structure.
 *
 * @internal
 */
final class RecordContent
{
    /**
     * @var array<string, list<Segment>>|null attribute => segments
     */
    private ?array $segments = null;

    /**
     * Attributes whose source can't be translated as text.
     *
     * @var array<string, string>
     */
    private array $unsupported = [];

    /**
     * @var array<string, array<string, Alignment>> locale => attribute => alignment
     */
    private array $alignments = [];

    public function __construct(
        public readonly Model&TranslatableModel $record,
        public readonly ContentSchema $schema,
        public readonly string $source,
    ) {}

    /**
     * @return array<string, list<Segment>>
     */
    public function segments(): array
    {
        if ($this->segments !== null) {
            return $this->segments;
        }

        $this->segments = [];
        $this->unsupported = [];

        foreach ($this->schema->attributes() as $attribute) {
            try {
                $this->segments[$attribute] = $this->schema->segments($attribute, $this->sourceValue($attribute));
            } catch (UnsupportedContent $exception) {
                $this->segments[$attribute] = [];
                $this->unsupported[$attribute] = $exception->getMessage();
            }
        }

        return $this->segments;
    }

    /**
     * @return array<string, Segment> key => segment
     */
    public function segmentsByKey(): array
    {
        $segments = [];

        foreach ($this->segments() as $attributeSegments) {
            foreach ($attributeSegments as $segment) {
                $segments[$segment->key()] = $segment;
            }
        }

        return $segments;
    }

    public function isUnsupported(string $attribute): bool
    {
        $this->segments();

        return isset($this->unsupported[$attribute]);
    }

    public function sourceValue(string $attribute): mixed
    {
        return $this->record->getTranslation($attribute, $this->source);
    }

    public function alignment(string $locale, string $attribute): Alignment
    {
        if ($locale === $this->source) {
            $alignment = new Alignment;

            foreach ($this->segments()[$attribute] ?? [] as $segment) {
                $alignment->texts[$segment->path] = $segment->text;
            }

            return $alignment;
        }

        return $this->alignments[$locale][$attribute] ??= $this->isUnsupported($attribute)
            ? new Alignment
            : $this->schema->align($attribute, $this->sourceValue($attribute), $this->record->getTranslation($attribute, $locale));
    }

    /**
     * The stored texts of a locale by segment key.
     *
     * @return array<string, string>
     */
    public function texts(string $locale, ?string $attribute = null): array
    {
        $texts = [];

        foreach ($this->segments() as $name => $segments) {
            if ($attribute !== null && $name !== $attribute) {
                continue;
            }

            $aligned = $this->alignment($locale, $name)->texts;

            foreach ($segments as $segment) {
                if (isset($aligned[$segment->path])) {
                    $texts[$segment->key()] = $aligned[$segment->path];
                }
            }
        }

        return $texts;
    }

    /**
     * @return array{translated: int, total: int, review: list<string>}
     */
    public function progress(string $locale): array
    {
        $translated = 0;
        $total = 0;
        $review = [];

        foreach ($this->segments() as $attribute => $segments) {
            $total += count($segments);
            $translated += count(array_intersect_key($this->alignment($locale, $attribute)->texts, array_flip(array_map(fn (Segment $segment): string => $segment->path, $segments))));

            if ($this->alignment($locale, $attribute)->orphans !== []) {
                $review[] = $attribute;
            }
        }

        return ['translated' => $translated, 'total' => $total, 'review' => $review];
    }

    public function flush(): void
    {
        $this->segments = null;
        $this->unsupported = [];
        $this->alignments = [];
    }
}
