<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament;

use Closure;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Tipi\Translations\Filament\Content\SegmentText;
use Tipi\Translations\Filament\Enums\EditorLayout;
use Tipi\Translations\Filament\Enums\EditorPresentation;
use Tipi\Translations\Filament\Enums\LocaleNavigation;

/**
 * How a record's translations are edited: where the editor opens
 * (presentation), how it arranges fields (layout), how editors switch
 * locales (navigation), which locales it offers and which form it uses.
 *
 * Presentation and layout are independent: every combination uses the same
 * form, locales, validation and persistence.
 *
 *     $editor->page()->comparison();
 *     $editor->modal()->localeTabs();
 *
 * Defaults: a modal with the comparison layout, editing the locales of
 * Localization's default context. A panel changes the defaults with
 * FilamentTranslationsPlugin::editor(), a resource with translationEditor(),
 * and a single action with TranslateAction::editor().
 */
final class TranslationEditor
{
    /**
     * Up to this many locales, automatic navigation uses tabs.
     */
    public const int TABS_LIMIT = 5;

    private EditorPresentation $presentation = EditorPresentation::Modal;

    private EditorLayout $layout = EditorLayout::Comparison;

    private ?LocaleNavigation $navigation = null;

    private ?Closure $form = null;

    /**
     * @var class-string<resource>|null
     */
    private ?string $resource = null;

    private ?string $context = null;

    /**
     * @var list<string>|Closure|null
     */
    private array|Closure|null $locales = null;

    private bool $hasInactiveLocales = false;

    private string|Closure|null $sourceLocale = null;

    /**
     * @var list<string>|null
     */
    private ?array $placeholders = null;

    private ?string $itemKey = 'id';

    /**
     * A new editor with the current panel's defaults applied.
     */
    public static function make(): self
    {
        $editor = new self;

        $panel = Filament::getCurrentPanel();

        if ($panel?->hasPlugin(FilamentTranslationsPlugin::ID)) {
            /** @var FilamentTranslationsPlugin $plugin */
            $plugin = $panel->getPlugin(FilamentTranslationsPlugin::ID);

            return $plugin->configureEditor($editor);
        }

        return $editor;
    }

    /**
     * Open the editor on the resource's translation page.
     */
    public function page(): self
    {
        $this->presentation = EditorPresentation::Page;

        return $this;
    }

    /**
     * Open the editor in a modal.
     */
    public function modal(): self
    {
        $this->presentation = EditorPresentation::Modal;

        return $this;
    }

    /**
     * Open the editor in a modal that slides over from the side.
     */
    public function slideOver(): self
    {
        $this->presentation = EditorPresentation::SlideOver;

        return $this;
    }

    /**
     * Edit each target field beside the same field of a read-only source
     * locale. Target locales are switched with tabs or a select; by default
     * tabs up to five locales.
     */
    public function comparison(?LocaleNavigation $navigation = null): self
    {
        $this->layout = EditorLayout::Comparison;
        $this->navigation = $navigation;

        return $this;
    }

    /**
     * Edit one locale at a time, switching locales with tabs.
     */
    public function localeTabs(): self
    {
        $this->layout = EditorLayout::LocaleFocused;
        $this->navigation = LocaleNavigation::Tabs;

        return $this;
    }

    /**
     * Edit one locale at a time, switching locales with a select.
     */
    public function localeSelect(): self
    {
        $this->layout = EditorLayout::LocaleFocused;
        $this->navigation = LocaleNavigation::Select;

        return $this;
    }

    /**
     * The translation form: standard Filament components named after
     * translatable attributes. Called once per locale.
     *
     *     fn (Schema $schema, Locale $locale): Schema => $schema->components([...])
     *
     * Resources using the Translatable concern set it from translationForm().
     */
    public function form(Closure $form): self
    {
        $this->form = $form;

        return $this;
    }

    /**
     * The resource the edited records belong to: it provides the page,
     * record titles and the fallback authorization.
     *
     * @param  class-string<resource>|null  $resource
     */
    public function resource(?string $resource): self
    {
        if ($resource !== null && ! is_subclass_of($resource, Resource::class)) {
            throw new LogicException("[{$resource}] is not a Filament resource.");
        }

        $this->resource = $resource;

        return $this;
    }

    /**
     * The locale context the content is published in: its locales are
     * offered by default, its default is the default source, and fallback
     * status is resolved as its readers see it. Defaults to Localization's
     * default context. This is independent of the panel's interface locale.
     */
    public function context(?string $context): self
    {
        $this->context = $context;

        return $this;
    }

    /**
     * The locales to edit, in place of the context's locales. Any locale in
     * the registry may be listed, active or not.
     *
     * @param  list<string>|Closure(Model): list<string>|null  $locales
     */
    public function locales(array|Closure|null $locales): self
    {
        $this->locales = $locales;

        return $this;
    }

    /**
     * Also offer inactive locales, to prepare content before a locale goes
     * live. They are marked as inactive in the editor.
     */
    public function inactiveLocales(bool $condition = true): self
    {
        $this->hasInactiveLocales = $condition;

        return $this;
    }

    /**
     * The canonical source locale: the language content is authored in.
     * Its content decides what there is to translate, and the structure
     * translations keep. The global default locale by default; a closure
     * can choose per record. Translators can show another language beside
     * the target, but that never changes what is translated.
     *
     * @param  string|Closure(Model): ?string|null  $locale
     */
    public function sourceLocale(string|Closure|null $locale): self
    {
        $this->sourceLocale = $locale;

        return $this;
    }

    /**
     * Placeholders translations must keep, as regular expressions. By
     * default {{ name }}, {name}, :name and %s. An empty list checks none.
     *
     * @param  list<string>|null  $patterns
     */
    public function placeholders(?array $patterns): self
    {
        $this->placeholders = $patterns === null ? null : array_values($patterns);

        return $this;
    }

    /**
     * The key of the id field of repeater and builder items ("id" by
     * default). Items with an id keep their translations when the source
     * adds, removes or reorders items; others are matched by position.
     */
    public function itemKey(?string $key): self
    {
        $this->itemKey = $key;

        return $this;
    }

    public function getPresentation(): EditorPresentation
    {
        return $this->presentation;
    }

    public function getLayout(): EditorLayout
    {
        return $this->layout;
    }

    public function isComparison(): bool
    {
        return $this->layout === EditorLayout::Comparison;
    }

    /**
     * The navigation for the given number of switchable locales.
     */
    public function getNavigation(int $locales): LocaleNavigation
    {
        return $this->navigation ?? ($locales <= self::TABS_LIMIT ? LocaleNavigation::Tabs : LocaleNavigation::Select);
    }

    public function getForm(): Closure
    {
        return $this->form ?? throw new LogicException(
            'The translation editor has no form. Use the Translatable concern on the resource, or call form() on the editor.',
        );
    }

    /**
     * @return class-string<resource>|null
     */
    public function getResource(): ?string
    {
        return $this->resource;
    }

    public function getContext(): ?string
    {
        return $this->context;
    }

    /**
     * @return list<string>|null
     */
    public function getLocales(Model $record): ?array
    {
        $locales = $this->locales instanceof Closure ? ($this->locales)($record) : $this->locales;

        return $locales === null ? null : array_values(array_map(strval(...), $locales));
    }

    public function hasInactiveLocales(): bool
    {
        return $this->hasInactiveLocales;
    }

    /**
     * @return list<string>
     */
    public function getPlaceholders(): array
    {
        return $this->placeholders ?? SegmentText::PLACEHOLDERS;
    }

    public function getItemKey(): ?string
    {
        return $this->itemKey;
    }

    public function getSourceLocale(Model $record): ?string
    {
        return $this->sourceLocale instanceof Closure ? ($this->sourceLocale)($record) : $this->sourceLocale;
    }
}
