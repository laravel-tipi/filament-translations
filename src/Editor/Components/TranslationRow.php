<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Editor\Components;

use Filament\Actions\Action;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Tipi\Support\Locale;
use Tipi\Translations\Filament\Editor\RecordEditor;
use Tipi\Translations\Filament\Enums\FieldStatus;

/**
 * One field of the translation form, bound to a target locale, with its
 * stored status and, in the comparison layout, a read-only copy of the
 * same field bound to the source locale.
 *
 * @internal
 */
final class TranslationRow extends Component
{
    protected string $view = 'tipi-filament-translations::components.translation-row';

    private RecordEditor $editor;

    private Locale $locale;

    private ?Locale $source = null;

    private string $path;

    /**
     * @var array<string, array{lang: string, dir: string}>
     */
    private array $cellLanguage = [];

    public static function make(RecordEditor $editor, Component $unit, ?Component $twin, Locale $locale, ?Locale $source): self
    {
        $row = app(self::class);
        $row->editor = $editor;
        $row->locale = $locale;
        $row->source = $twin === null ? null : $source;
        $row->path = (string) $unit->getStatePath(isAbsolute: false);
        $row->configure();

        $row->key('tipi-translation-'.$locale->code.'-'.str_replace('.', '-', $row->path));
        $row->applyLanguage('target', $unit, $locale);

        $row->childComponents([
            Group::make([$unit])
                ->statePath(RecordEditor::TRANSLATIONS.'.'.$locale->code)
                // Locales opened without changes are neither validated nor saved.
                ->dehydrated(fn (Group $component): bool => $editor->isChanged($component->getRootContainer(), $locale->code))
                ->validatedWhenNotDehydrated(false),
        ], 'target');

        if ($twin !== null && $source !== null) {
            self::makeReadOnly($twin);
            $row->applyLanguage('source', $twin, $source);

            $row->childComponents([
                Group::make([$twin])
                    ->statePath(RecordEditor::SOURCE)
                    ->dehydrated(false)
                    ->validatedWhenNotDehydrated(false),
            ], 'source');

            $row->childComponents([
                Action::make('copySource')
                    ->label(__('tipi-filament-translations::editor.row.copy_source'))
                    ->tooltip(fn (): string => __('tipi-filament-translations::editor.row.copy_source_tooltip', ['locale' => $row->getSourceLocale()?->name]))
                    ->icon(Heroicon::DocumentDuplicate)
                    ->color('gray')
                    ->size(Size::Small)
                    ->link()
                    ->visible(fn (): bool => $row->hasSourceValue())
                    ->action(fn () => $editor->copySource(
                        $row->getRootContainer(),
                        $locale,
                        $row->path,
                        $row->getSourceLocale()?->code,
                    )),
            ], 'actions');
        }

        return $row;
    }

    /**
     * Content is marked with its language and direction: on the input when
     * the field supports it, so labels keep the interface's direction, or
     * on the whole cell for composite fields.
     */
    private function applyLanguage(string $cell, Component $unit, Locale $locale): void
    {
        $attributes = ['lang' => $locale->code, 'dir' => $locale->textDirection->value];

        if (method_exists($unit, 'extraInputAttributes')) {
            $unit->extraInputAttributes($attributes, merge: true);

            return;
        }

        $this->cellLanguage[$cell] = $attributes;
    }

    /**
     * @return array{lang?: string, dir?: string}
     */
    public function getCellLanguage(string $cell): array
    {
        return $this->cellLanguage[$cell] ?? [];
    }

    /**
     * The source copy shows the same component, so its structure, labels
     * and formatting match. Not dehydrated: never validated or saved.
     */
    private static function makeReadOnly(Component $twin): void
    {
        $twin->dehydrated(false)->validatedWhenNotDehydrated(false);

        if (method_exists($twin, 'readOnly')) {
            $twin->readOnly();
        } elseif (method_exists($twin, 'disabled')) {
            $twin->disabled();
        }

        if (method_exists($twin, 'markAsRequired')) {
            $twin->markAsRequired(false);
        }
    }

    public function getLocale(): Locale
    {
        return $this->locale;
    }

    public function isComparison(): bool
    {
        return $this->source !== null;
    }

    /**
     * The source locale currently selected, which may differ from the one
     * the row was built with while the editor is changing it.
     */
    public function getSourceLocale(): ?Locale
    {
        $code = $this->getRootContainer()->getRawState()[RecordEditor::SOURCE_LOCALE] ?? null;

        return is_string($code) ? $this->editor->sourceOptions([])->get($code) : $this->source;
    }

    public function hasSourceValue(): bool
    {
        $source = $this->getSourceLocale();

        return $source !== null && $this->editor->record->hasTranslation(RecordEditor::attributeOf($this->getTargetUnit()), $source->code);
    }

    /**
     * @return array{FieldStatus, Locale|null}
     */
    public function getStatus(): array
    {
        return $this->editor->fieldStatus(RecordEditor::attributeOf($this->getTargetUnit()), $this->locale->code);
    }

    public function getStatusLabel(): string
    {
        [$status, $fallback] = $this->getStatus();

        return match ($status) {
            FieldStatus::Translated => __('tipi-filament-translations::editor.row.translated'),
            FieldStatus::Fallback => __('tipi-filament-translations::editor.row.fallback', ['locale' => $fallback?->name]),
            FieldStatus::Missing => __('tipi-filament-translations::editor.row.missing'),
        };
    }

    private function getTargetUnit(): Component
    {
        /** @var Group $group */
        $group = $this->getChildSchema('target')->getComponents(withHidden: true)[0];

        /** @var Component */
        return $group->getChildSchema()->getComponents(withHidden: true)[0];
    }
}
