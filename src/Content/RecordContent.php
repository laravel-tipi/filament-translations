<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Content;

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Concerns\HasTranslationStates;
use Tipi\Translations\Contracts\TranslatableModel;

/**
 * A record's translatable content: the segments of its source locale and
 * the translations lined up with them. A locale's translation is its
 * working copy: the unpublished draft when there is one, else the
 * published value.
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

    /**
     * Why the attribute's source can't be translated, for translators.
     */
    public function unsupportedReason(string $attribute): ?string
    {
        $this->segments();

        return $this->unsupported[$attribute] ?? null;
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
            : $this->schema->align($attribute, $this->sourceValue($attribute), $this->working($locale, $attribute));
    }

    public function supportsDrafts(): bool
    {
        return in_array(HasTranslationStates::class, class_uses_recursive($this->record), true);
    }

    /**
     * What translators work on: the unpublished draft, or else the
     * published translation.
     */
    public function working(string $locale, string $attribute): mixed
    {
        return $this->draft($locale, $attribute) ?? $this->record->getTranslation($attribute, $locale);
    }

    public function draft(string $locale, string $attribute): mixed
    {
        return $this->supportsDrafts() ? $this->record->getTranslationDraft($attribute, $locale) : null;
    }

    /**
     * The fingerprints of the source texts each translated segment was
     * made from: segment path => fingerprint.
     *
     * @return array<string, string>
     */
    public function sources(string $locale, string $attribute): array
    {
        $sources = $this->supportsDrafts() ? $this->record->getTranslationSource($attribute, $locale) : null;

        return is_array($sources) ? array_filter($sources, is_string(...)) : [];
    }

    /**
     * Translated segments whose source text changed since they were
     * translated: segment path => true. Only segments with a recorded
     * fingerprint can be told.
     *
     * @return array<string, true>
     */
    public function stale(string $locale, string $attribute): array
    {
        $texts = $this->alignment($locale, $attribute)->texts;
        $sources = $this->sources($locale, $attribute);
        $stale = [];

        foreach ($this->segments()[$attribute] ?? [] as $segment) {
            if (isset($texts[$segment->path], $sources[$segment->path]) && $sources[$segment->path] !== $segment->hash()) {
                $stale[$segment->path] = true;
            }
        }

        return $stale;
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
     * @return array{translated: int, total: int, review: list<string>, stale: int, drafts: list<string>, unsupported: list<string>}
     */
    public function progress(string $locale): array
    {
        $translated = 0;
        $total = 0;
        $stale = 0;
        $review = [];
        $drafts = [];

        foreach ($this->segments() as $attribute => $segments) {
            $total += count($segments);
            $translated += count(array_intersect_key($this->alignment($locale, $attribute)->texts, array_flip(array_map(fn (Segment $segment): string => $segment->path, $segments))));
            $stale += count($this->stale($locale, $attribute));

            if ($this->alignment($locale, $attribute)->orphans !== []) {
                $review[] = $attribute;
            }

            if ($this->draft($locale, $attribute) !== null) {
                $drafts[] = $attribute;
            }
        }

        // Content that can't be translated here still counts: it is
        // never left out of completeness unnoticed.
        $unsupported = array_keys($this->unsupported);

        return ['translated' => $translated, 'total' => $total, 'review' => $review, 'stale' => $stale, 'drafts' => $drafts, 'unsupported' => $unsupported];
    }

    public function flush(): void
    {
        $this->segments = null;
        $this->unsupported = [];
        $this->alignments = [];
    }
}
