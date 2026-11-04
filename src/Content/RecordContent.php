<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Content;

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Concerns\HasTranslationStates;
use Tipi\Translations\Contracts\TranslatableModel;

/**
 * A record's translatable content: the segments of its source locale and
 * the translations lined up with them, in two layers per locale and
 * attribute: the published value (what readers see) and the working copy
 * (what translators edit: the unpublished draft when there is one, else
 * the published value). Each layer has its own sources.
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
     * @var array<string, array<string, array<string, Alignment>>> locale => attribute => layer => alignment
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

    /**
     * The translation lined up with the source: the working copy, or the
     * published value.
     */
    public function alignment(string $locale, string $attribute, bool $published = false): Alignment
    {
        if ($locale === $this->source) {
            $alignment = new Alignment;

            foreach ($this->segments()[$attribute] ?? [] as $segment) {
                $alignment->texts[$segment->path] = $segment->text;
            }

            return $alignment;
        }

        $layer = $published || $this->draft($locale, $attribute) === null ? 'published' : 'draft';

        return $this->alignments[$locale][$attribute][$layer] ??= $this->isUnsupported($attribute)
            ? new Alignment
            : $this->schema->align($attribute, $this->sourceValue($attribute), $layer === 'draft'
                ? $this->draft($locale, $attribute)
                : $this->record->getTranslation($attribute, $locale));
    }

    public function supportsDrafts(): bool
    {
        return in_array(HasTranslationStates::class, class_uses_recursive($this->record), true);
    }

    public function draft(string $locale, string $attribute): mixed
    {
        return $this->supportsDrafts() ? $this->record->getTranslationDraft($attribute, $locale) : null;
    }

    /**
     * The fingerprints of the source texts each translated segment of a
     * layer was made from: segment path => fingerprint.
     *
     * @return array<string, string>
     */
    public function sources(string $locale, string $attribute, bool $published = false): array
    {
        if (! $this->supportsDrafts()) {
            return [];
        }

        $sources = ! $published && $this->draft($locale, $attribute) !== null
            ? $this->record->getTranslationDraftSource($attribute, $locale)
            : $this->record->getTranslationSource($attribute, $locale);

        return is_array($sources) ? array_filter($sources, is_string(...)) : [];
    }

    /**
     * Translated segments of a layer whose source text changed since they
     * were translated: segment path => true. Only segments with a recorded
     * fingerprint can be told.
     *
     * @return array<string, true>
     */
    public function stale(string $locale, string $attribute, bool $published = false): array
    {
        return array_fill_keys($this->outdated(
            $attribute,
            $this->alignment($locale, $attribute, $published)->texts,
            $this->sources($locale, $attribute, $published),
        ), true);
    }

    /**
     * Of the given texts (path => text, with their sources), those made
     * from a source text that changed since.
     *
     * @param  array<string, string>  $texts
     * @param  array<string, string>  $sources
     * @return list<string> paths
     */
    public function outdated(string $attribute, array $texts, array $sources): array
    {
        $paths = [];

        foreach ($this->segments()[$attribute] ?? [] as $segment) {
            if (isset($texts[$segment->path], $sources[$segment->path]) && $sources[$segment->path] !== $segment->hash()) {
                $paths[] = $segment->path;
            }
        }

        return $paths;
    }

    /**
     * Whether a translation (path => text, with its sources) may be
     * published: every segment translated, and no outdated text that
     * readers don't see already. Publishing never shows readers a
     * translation of an older source text they weren't shown before.
     *
     * @param  array<string, string>  $texts
     * @param  array<string, string>  $sources
     */
    public function publishable(string $locale, string $attribute, array $texts, array $sources): bool
    {
        return $this->blocking($locale, $attribute, $texts, $sources) === [] && $this->isComplete($attribute, $texts);
    }

    /**
     * The outdated texts that keep a translation from being published.
     *
     * @param  array<string, string>  $texts
     * @param  array<string, string>  $sources
     * @return list<string> paths
     */
    public function blocking(string $locale, string $attribute, array $texts, array $sources): array
    {
        $published = $this->alignment($locale, $attribute, published: true)->texts;

        return array_values(array_filter(
            $this->outdated($attribute, $texts, $sources),
            fn (string $path): bool => trim($published[$path] ?? '') !== trim($texts[$path]),
        ));
    }

    /**
     * @param  array<string, string>  $texts
     */
    public function isComplete(string $attribute, array $texts): bool
    {
        $segments = $this->segments()[$attribute] ?? [];

        if ($segments === [] || $this->isUnsupported($attribute)) {
            return false;
        }

        foreach ($segments as $segment) {
            if (! isset($texts[$segment->path])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Where an attribute's draft stands: null without a draft; else
     * "incomplete" (texts are missing), "outdated" (complete, but with
     * outdated texts readers don't see yet) or "ready" (it can be
     * published as it is: the source changed so that it is complete).
     */
    public function draftStatus(string $locale, string $attribute): ?string
    {
        if ($this->draft($locale, $attribute) === null) {
            return null;
        }

        $texts = $this->alignment($locale, $attribute)->texts;

        return match (true) {
            ! $this->isComplete($attribute, $texts) => 'incomplete',
            $this->blocking($locale, $attribute, $texts, $this->sources($locale, $attribute)) !== [] => 'outdated',
            default => 'ready',
        };
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
     * A locale's progress. Counts describe the published translations,
     * what readers see; "drafted" counts the working copies, with drafts.
     *
     * @return array{translated: int, drafted: int, total: int, review: list<string>, stale: int, drafts: list<string>, unsupported: list<string>}
     */
    public function progress(string $locale): array
    {
        $translated = 0;
        $drafted = 0;
        $total = 0;
        $stale = 0;
        $review = [];
        $drafts = [];

        foreach ($this->segments() as $attribute => $segments) {
            $paths = array_flip(array_map(fn (Segment $segment): string => $segment->path, $segments));
            $published = $this->alignment($locale, $attribute, published: true);

            $total += count($segments);
            $translated += count(array_intersect_key($published->texts, $paths));
            $drafted += count(array_intersect_key($this->alignment($locale, $attribute)->texts, $paths));
            $stale += count($this->stale($locale, $attribute, published: true));

            if ($published->orphans !== []) {
                $review[] = $attribute;
            }

            if ($this->draft($locale, $attribute) !== null) {
                $drafts[] = $attribute;
            }
        }

        // Content that can't be translated here still counts: it is
        // never left out of completeness unnoticed.
        $unsupported = array_keys($this->unsupported);

        return ['translated' => $translated, 'drafted' => $drafted, 'total' => $total, 'review' => $review, 'stale' => $stale, 'drafts' => $drafts, 'unsupported' => $unsupported];
    }

    public function flush(): void
    {
        $this->segments = null;
        $this->unsupported = [];
        $this->alignments = [];
    }
}
