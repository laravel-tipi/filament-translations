<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Content;

use Closure;
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
 * An attribute is published when its translation is complete, or, with
 * partial publishing, unit by unit: each plain text on its own, each
 * document (Markdown, rich text) once all of its texts are translated.
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

    /**
     * @param  (Closure(string): bool)|null  $partial  whether an attribute is published partially
     */
    public function __construct(
        public readonly Model&TranslatableModel $record,
        public readonly ContentSchema $schema,
        public readonly string $source,
        private readonly ?Closure $partial = null,
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
     * Whether the attribute is published unit by unit (see
     * partialPublication()) rather than only when complete.
     */
    public function publishesPartially(string $attribute): bool
    {
        return $this->partial !== null && $this->supportsDrafts() && ($this->partial)($attribute);
    }

    /**
     * What publishing a working copy (path => text, with its sources)
     * partially shows readers: the published translation, with each unit
     * of the working copy that may be published taking its place. A unit
     * is a plain text, or all the texts of a document: documents are
     * published whole, never with gaps. A unit may be published when it is
     * translated and has no outdated text readers don't see already (see
     * blocking()). A unit is removed only when this save cleared it; one
     * that is just missing from the working copy keeps what readers see.
     * Kept texts keep their sources, so they stay as outdated as they are.
     *
     * Null when the attribute can't be published partially: its source
     * can't be translated, or what is published has texts the source
     * still has a place for, in another structure, which rebuilding from
     * the source would drop. It is then published only when complete.
     *
     * @param  array<string, string>  $texts
     * @param  array<string, string>  $sources
     * @param  list<string>  $cleared  paths this save cleared
     * @return array{texts: array<string, string>, sources: array<string, string>}|null
     */
    public function partialPublication(string $locale, string $attribute, array $texts, array $sources, array $cleared = []): ?array
    {
        $published = $this->alignment($locale, $attribute, published: true);

        if ($this->isUnsupported($attribute) || $published->restructured !== []) {
            return null;
        }

        $publishedSources = $this->sources($locale, $attribute, published: true);
        $blocking = array_flip($this->blocking($locale, $attribute, $texts, $sources));
        $cleared = array_flip($cleared);
        $publication = ['texts' => [], 'sources' => []];

        foreach ($this->units($attribute) as $paths) {
            $paths = array_flip($paths);
            $working = array_intersect_key($texts, $paths);

            [$from, $fromSources] = match (true) {
                count($working) === count($paths) && array_intersect_key($blocking, $paths) === [] => [$working, $sources],
                $working === [] && array_intersect_key($cleared, $paths) !== [] => [[], []],
                default => [array_intersect_key($published->texts, $paths), $publishedSources],
            };

            $publication['texts'] += $from;
            $publication['sources'] += array_intersect_key($fromSources, $from);
        }

        return $publication;
    }

    /**
     * Whether a save of the attribute's working copy would publish it
     * partially (see partialPublication()).
     */
    public function isPartiallyPublishable(string $locale, string $attribute): bool
    {
        return $this->publishesPartially($attribute)
            && $this->partialPublication($locale, $attribute, $this->alignment($locale, $attribute)->texts, $this->sources($locale, $attribute)) !== null;
    }

    /**
     * The paths of the attribute's units of publication: each plain text,
     * and the texts of each document together.
     *
     * @return list<list<string>>
     */
    private function units(string $attribute): array
    {
        $units = [];

        foreach ($this->segments()[$attribute] ?? [] as $segment) {
            $units[$segment->document === null ? 'text:'.$segment->path : 'document:'.$segment->document][] = $segment->path;
        }

        return array_values($units);
    }

    /**
     * Whether two translations (path => text) have the same texts.
     *
     * @param  array<string, string>  $a
     * @param  array<string, string>  $b
     */
    public static function sameTexts(array $a, array $b): bool
    {
        $normalize = fn (array $texts): array => array_map(fn (string $text): string => trim(str_replace(["\r\n", "\r"], "\n", $text)), $texts);
        $a = $normalize($a);
        $b = $normalize($b);
        ksort($a);
        ksort($b);

        return $a === $b;
    }

    /**
     * Where an attribute's draft stands: null without a draft; else
     * "incomplete" (texts are missing), "outdated" (complete, but with
     * outdated texts readers don't see yet) or "ready" (it can be
     * published as it is: the source changed so that it is complete).
     *
     * Published partially, the draft is what isn't published yet:
     * "ready" when some of it can be published as it is, "outdated" when
     * outdated texts keep some of it from being published, "incomplete"
     * otherwise (a document isn't completely translated).
     */
    public function draftStatus(string $locale, string $attribute): ?string
    {
        if ($this->draft($locale, $attribute) === null) {
            return null;
        }

        $texts = $this->alignment($locale, $attribute)->texts;
        $sources = $this->sources($locale, $attribute);
        $publication = $this->publishesPartially($attribute) ? $this->partialPublication($locale, $attribute, $texts, $sources) : null;

        if ($publication !== null) {
            return match (true) {
                ! self::sameTexts($publication['texts'], $this->alignment($locale, $attribute, published: true)->texts) => 'ready',
                $this->blocking($locale, $attribute, $texts, $sources) !== [] => 'outdated',
                default => 'incomplete',
            };
        }

        return match (true) {
            ! $this->isComplete($attribute, $texts) => 'incomplete',
            $this->blocking($locale, $attribute, $texts, $sources) !== [] => 'outdated',
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
