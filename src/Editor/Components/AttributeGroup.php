<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Editor\Components;

use Filament\Schemas\Components\Component;
use Tipi\Support\Locale;
use Tipi\Translations\Filament\Content\Segment;
use Tipi\Translations\Filament\Editor\RecordEditor;

/**
 * The segments of one attribute in a locale, with what is known about the
 * attribute: progress, what readers see while it is missing or has an
 * unpublished draft, and texts that no longer fit the source.
 *
 * @internal
 */
final class AttributeGroup extends Component
{
    protected string $view = 'tipi-filament-translations::components.attribute-group';

    private RecordEditor $editor;

    private Locale $locale;

    private string $attribute;

    /**
     * @var list<Segment>
     */
    private array $segments;

    /**
     * @param  list<Segment>  $segments
     */
    public static function make(RecordEditor $editor, Locale $locale, string $attribute, array $segments): self
    {
        $group = app(self::class);
        $group->editor = $editor;
        $group->locale = $locale;
        $group->attribute = $attribute;
        $group->segments = $segments;
        $group->configure();

        $group
            ->key("tipi-translation-{$locale->code}-{$attribute}")
            ->childComponents(array_map(
                fn (Segment $segment): SegmentRow => SegmentRow::make($editor, $locale, $segment),
                $segments,
            ));

        return $group;
    }

    public function getLabel(): string
    {
        return $this->editor->schema()->label($this->attribute);
    }

    public function getAttributeName(): string
    {
        return $this->attribute;
    }

    public function getLocale(): Locale
    {
        return $this->locale;
    }

    public function getSourceLocale(): Locale
    {
        return $this->editor->sourceLocale();
    }

    /**
     * @return array{translated: int, total: int}
     */
    public function getProgress(): array
    {
        $texts = $this->editor->alignment($this->locale->code, $this->attribute)->texts;
        $paths = array_map(fn (Segment $segment): string => $segment->path, $this->segments);

        return ['translated' => count(array_intersect_key($texts, array_flip($paths))), 'total' => count($this->segments)];
    }

    public function isUnsupported(): bool
    {
        return $this->editor->content()->isUnsupported($this->attribute);
    }

    /**
     * Stored texts that no longer line up with the source.
     *
     * @return list<string>
     */
    public function getOrphans(): array
    {
        return $this->editor->alignment($this->locale->code, $this->attribute)->orphans;
    }

    /**
     * The translator's texts a save couldn't keep because the source's
     * structure changed.
     *
     * @return list<string>
     */
    public function getUnsavedTexts(): array
    {
        $texts = $this->editor->conflicts($this->getRootContainer()->getRawState(), $this->locale->code)['attributes'][$this->attribute] ?? [];

        return array_values(array_filter($texts, is_string(...)));
    }

    public function getFallbackLocale(): ?Locale
    {
        return $this->editor->fallbackLocale($this->attribute, $this->locale->code);
    }

    public function getUnsupportedReason(): ?string
    {
        return $this->editor->content()->unsupportedReason($this->attribute);
    }

    /**
     * When the attribute has an unpublished draft: where it stands
     * (RecordContent::draftStatus()), what readers see meanwhile, and how
     * many of its texts block publishing.
     *
     * @return array{status: string, readers: string, blocking: int}|null
     */
    public function getDraft(): ?array
    {
        $content = $this->editor->content();
        $locale = $this->locale->code;
        $status = $content->draftStatus($locale, $this->attribute);

        if ($status === null) {
            return null;
        }

        $texts = $content->alignment($locale, $this->attribute)->texts;

        return [
            'status' => $status,
            'readers' => $this->getReadersSee(),
            'blocking' => count($content->blocking($locale, $this->attribute, $texts, $content->sources($locale, $this->attribute))),
        ];
    }

    /**
     * What readers of the locale see of the attribute now.
     */
    public function getReadersSee(): string
    {
        $locale = $this->locale->code;

        if ($this->editor->record->getTranslation($this->attribute, $locale) !== null) {
            $published = $this->editor->content()->alignment($locale, $this->attribute, published: true)->texts;
            $paths = array_map(fn (Segment $segment): string => $segment->path, $this->segments);

            return __('tipi-filament-translations::editor.attribute.readers_published', [
                'translated' => count(array_intersect_key($published, array_flip($paths))),
                'total' => count($this->segments),
            ]);
        }

        $fallback = $this->getFallbackLocale();

        return $fallback === null
            ? __('tipi-filament-translations::editor.attribute.readers_nothing')
            : __('tipi-filament-translations::editor.attribute.readers_fallback', ['locale' => $fallback->name]);
    }

    public function isPublishRequested(): bool
    {
        return in_array($this->attribute, $this->editor->publishRequests($this->getRootContainer()->getRawState(), $this->locale->code), true);
    }

    public function getPublishStatePath(): string
    {
        $root = $this->getRootContainer()->getStatePath();

        return (filled($root) ? $root.'.' : '').RecordEditor::PUBLISH.'.'.$this->locale->code.'.'.$this->attribute;
    }

    public function getStaleCount(): int
    {
        return count($this->editor->content()->stale($this->locale->code, $this->attribute));
    }
}
