<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Editor\Components;

use Closure;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Component;
use Illuminate\Support\HtmlString;
use Tipi\Support\Locale;
use Tipi\Translations\Filament\Content\Inline;
use Tipi\Translations\Filament\Content\InvalidTranslation;
use Tipi\Translations\Filament\Content\Segment;
use Tipi\Translations\Filament\Content\SegmentText;
use Tipi\Translations\Filament\Editor\RecordEditor;

/**
 * One segment: the reference text, and a textarea for the translation.
 *
 * @internal
 */
final class SegmentRow extends Component
{
    protected string $view = 'tipi-filament-translations::components.segment-row';

    private RecordEditor $editor;

    private Locale $locale;

    private Segment $segment;

    public static function make(RecordEditor $editor, Locale $locale, Segment $segment): self
    {
        $row = app(self::class);
        $row->editor = $editor;
        $row->locale = $locale;
        $row->segment = $segment;
        $row->configure();

        $row
            ->key("tipi-translation-{$locale->code}-{$segment->key()}")
            ->childComponents([$row->input()]);

        return $row;
    }

    private function input(): Textarea
    {
        $editor = $this->editor;
        $segment = $this->segment;
        $code = $this->locale->code;

        return Textarea::make($segment->key())
            ->label($this->getLabel())
            ->hiddenLabel()
            ->rows(1)
            ->autosize()
            ->extraInputAttributes([
                'lang' => $code,
                'dir' => $this->locale->textDirection->value,
                'spellcheck' => 'true',
                'class' => 'fi-tipi-tr-input',
            ])
            ->rule(fn (Textarea $component): Closure => function (string $attribute, mixed $value, Closure $fail) use ($editor, $segment, $code, $component): void {
                // Only what the translator changed: a stored text never blocks a draft.
                if (! array_key_exists($segment->key(), $editor->changes($component->getRootContainer()->getRawState(), $code))) {
                    return;
                }

                try {
                    SegmentText::read($segment, $value, $editor->placeholders());
                } catch (InvalidTranslation $exception) {
                    $fail($exception->getMessage());
                }
            });
    }

    public function getSegment(): Segment
    {
        return $this->segment;
    }

    public function getLocale(): Locale
    {
        return $this->locale;
    }

    public function isComparison(): bool
    {
        return $this->editor->config->isComparison();
    }

    /**
     * Where the segment sits: item and field, and what kind of text it is.
     */
    public function getLabel(): string
    {
        $parts = $this->segment->context;

        if ($this->segment->role !== 'text') {
            $parts[] = __('tipi-filament-translations::editor.roles.'.$this->segment->role);
        }

        return $parts === []
            ? $this->editor->schema()->label($this->segment->attribute)
            : implode(' › ', $parts);
    }

    public function getReferenceLocale(): Locale
    {
        $code = $this->getRootContainer()->getRawState()[RecordEditor::REFERENCE] ?? null;

        if (! $this->isComparison() || ! is_string($code) || ! $this->editor->referenceOptions($this->locale->code)->has($code)) {
            return $this->editor->sourceLocale();
        }

        return $this->editor->referenceOptions($this->locale->code)->get($code);
    }

    public function getReferenceText(): ?string
    {
        $reference = $this->getReferenceLocale();

        return $reference->code === $this->editor->sourceLocale()->code
            ? $this->segment->text
            : $this->editor->referenceText($reference->code, $this->segment);
    }

    public function getSourceText(): string
    {
        return $this->segment->text;
    }

    public function getInputStatePath(): string
    {
        $root = $this->getRootContainer()->getStatePath();

        return (filled($root) ? $root.'.' : '').RecordEditor::TRANSLATIONS.'.'.$this->locale->code.'.'.$this->segment->key();
    }

    public function isTranslated(): bool
    {
        return isset($this->editor->alignment($this->locale->code, $this->segment->attribute)->texts[$this->segment->path]);
    }

    /**
     * @return array{reason: string, theirs: string|null}|null
     */
    public function getConflict(): ?array
    {
        $conflict = $this->editor->conflicts($this->getRootContainer()->getRawState(), $this->locale->code)['segments'][$this->segment->key()] ?? null;

        return is_array($conflict) && is_string($conflict['reason'] ?? null) ? $conflict : null;
    }

    public function getConflictStatePath(): string
    {
        $root = $this->getRootContainer()->getStatePath();

        return (filled($root) ? $root.'.' : '').RecordEditor::CONFLICTS.'.'.$this->locale->code.'.segments.'.$this->segment->key();
    }

    /**
     * A text with its tags shown as tags, for reading.
     */
    public function markup(?string $text): HtmlString
    {
        if ($text === null) {
            return new HtmlString('');
        }

        $html = e($text);

        if ($this->segment->rich) {
            $tags = $this->segment->tags;

            $html = (string) preg_replace_callback(
                '/&lt;(\/?)([a-z]+[1-9][0-9]*)(\/?)&gt;/',
                fn (array $match): string => isset($tags[$match[2]])
                    ? '<span class="fi-tipi-tr-tag" title="'.e($tags[$match[2]]->description).'">'.$match[0].'</span>'
                    : $match[0],
                $html,
            );
        }

        return new HtmlString(nl2br($html, false));
    }

    public function plain(?string $text): string
    {
        return $text === null ? '' : ($this->segment->rich ? Inline::plain(Inline::parse($text, $this->segment->tagForms())) : $text);
    }
}
