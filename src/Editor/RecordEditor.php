<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Editor;

use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use LogicException;
use Tipi\Localization\LocaleContext;
use Tipi\Localization\LocaleRegistry;
use Tipi\Support\Locale;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Filament\Editor\Components\LocaleNavigation;
use Tipi\Translations\Filament\Editor\Components\LocalePanel;
use Tipi\Translations\Filament\Editor\Components\TranslationRow;
use Tipi\Translations\Filament\Enums\FieldStatus;
use Tipi\Translations\Filament\Exceptions\TranslationConflictException;
use Tipi\Translations\Filament\Support\ContentContext;
use Tipi\Translations\Filament\Support\LocaleProgress;
use Tipi\Translations\Filament\Support\TranslationProgress;
use Tipi\Translations\Filament\TranslationEditor;

/**
 * The translation editor of one record: the engine behind every
 * presentation (page, modal) and layout (comparison, locale-focused).
 *
 * Editor state, relative to the host schema:
 *
 *     locale         the target locale shown
 *     source_locale  the read-only source (comparison layout)
 *     loaded         locale => fingerprint of the stored values when loaded
 *     translations   locale => attribute => value, for loaded locales only
 *     source         attribute => value of the source locale
 *
 * Contract:
 * - Fields are filled with exactly what is stored for their locale, never
 *   with fallback values.
 * - Locales are loaded when first opened. Saving writes every loaded locale
 *   in one save() of the model: one transaction, unchanged values not
 *   written, other locales and the source untouched.
 * - A loaded locale that changed in the database since it was loaded is a
 *   conflict: nothing is saved.
 * - Locale codes and attributes in the state are never trusted: only
 *   offered, authorized locales and the form's attributes are written.
 *
 * @internal
 */
final class RecordEditor
{
    public const string LOCALE = 'locale';

    public const string SOURCE_LOCALE = 'source_locale';

    public const string LOADED = 'loaded';

    public const string TRANSLATIONS = 'translations';

    public const string SOURCE = 'source';

    public readonly LocaleContext $context;

    /**
     * @var Collection<string, Locale>|null
     */
    private ?Collection $offeredLocales = null;

    /**
     * @var Collection<string, Locale>|null
     */
    private ?Collection $locales = null;

    /**
     * @var list<string>|null
     */
    private ?array $attributes = null;

    /**
     * Attributes edited as rich text, whose empty documents are empty values.
     *
     * @var array<string, true>
     */
    private array $richTextAttributes = [];

    private ?TranslationProgress $progress = null;

    public function __construct(
        public readonly TranslationEditor $config,
        public readonly Model&TranslatableModel $record,
    ) {
        $this->context = app(ContentContext::class)->get($config->getContext());
    }

    /*
    |--------------------------------------------------------------------------
    | Locales
    |--------------------------------------------------------------------------
    */

    /**
     * The locales the user may edit, in display order.
     *
     * @return Collection<string, Locale>
     */
    public function locales(): Collection
    {
        return $this->locales ??= $this->offeredLocales()->filter(
            fn (Locale $locale): bool => $this->canTranslate($locale->code),
        );
    }

    /**
     * The locales shown in the navigation: every editable locale except
     * the source in the comparison layout.
     *
     * @return Collection<string, Locale>
     */
    public function targets(?string $source): Collection
    {
        return $this->config->isComparison() && $source !== null
            ? $this->locales()->except($source)
            : $this->locales();
    }

    /**
     * Locales that can be the source: every offered or stored locale except
     * the locales being edited, which can't be read-only at the same time.
     *
     * @param  list<string>  $editing
     * @return Collection<string, Locale>
     */
    public function sourceOptions(array $editing): Collection
    {
        $stored = $this->record->getTranslatedLocales();
        $offered = $this->offeredLocales();

        return $this->registry()->all()->filter(
            fn (Locale $locale): bool => ($offered->has($locale->code) || in_array($locale->code, $stored, true))
                && ! in_array($locale->code, $editing, true),
        );
    }

    public function defaultSource(?string $except = null): ?string
    {
        if (! $this->config->isComparison()) {
            return null;
        }

        $configured = $this->config->getSourceLocale($this->record);

        if ($configured !== null && $configured !== $except) {
            return $this->registry()->get($configured)->code;
        }

        $stored = array_values(array_diff($this->record->getTranslatedLocales(), [$except]));

        foreach ([$this->context->defaultCode(), $this->registry()->defaultCode()] as $code) {
            if (in_array($code, $stored, true)) {
                return $code;
            }
        }

        if ($stored !== []) {
            return $stored[0];
        }

        return $this->sourceOptions(array_filter([$except]))->keys()->first();
    }

    public function defaultLocale(?string $requested, ?string $source): ?string
    {
        $targets = $this->targets($source);

        if ($requested !== null && $targets->has($requested)) {
            return $requested;
        }

        if ($this->config->isComparison()) {
            $needsWork = $targets->first(function (Locale $locale): bool {
                $progress = $this->progress()->locale($locale->code);

                return $progress !== null && ($progress->missing() > 0 || $progress->isOutdated());
            });

            if ($needsWork !== null) {
                return $needsWork->code;
            }
        } elseif ($targets->has($this->context->defaultCode())) {
            return $this->context->defaultCode();
        }

        return $targets->keys()->first();
    }

    /**
     * The locales the editor offers, before authorization.
     *
     * @return Collection<string, Locale>
     */
    public function offeredLocales(): Collection
    {
        if ($this->offeredLocales !== null) {
            return $this->offeredLocales;
        }

        $registry = $this->registry();
        $codes = $this->config->getLocales($this->record);

        if ($codes !== null) {
            foreach ($codes as $code) {
                $registry->get($code);
            }

            return $this->offeredLocales = $registry->all()->only($codes);
        }

        $codes = $this->context->codes();

        if ($this->config->hasInactiveLocales()) {
            $codes = [...$codes, ...$registry->all()->filter(fn (Locale $locale): bool => $locale->isInactive())->keys()];
        }

        return $this->offeredLocales = $registry->all()->only($codes);
    }

    private function registry(): LocaleRegistry
    {
        return app(LocaleRegistry::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    |
    | A policy (or gate) "translate" ability receives the record and the
    | locale code, so translators can be limited to their languages:
    |
    |     public function translate(User $user, Wine $wine, string $locale): bool
    |
    | Without it, translating requires the resource's edit permission, or
    | the "update" ability without a resource. "deleteTranslation" works the
    | same way and falls back to "translate".
    |
    */

    public function canAccess(): bool
    {
        return $this->locales()->isNotEmpty();
    }

    public function canTranslate(string $locale): bool
    {
        if (! $this->offeredLocales()->has($locale)) {
            return false;
        }

        return $this->allows('translate', $locale) ?? $this->canUpdateRecord();
    }

    public function canDelete(string $locale): bool
    {
        if (! $this->canTranslate($locale)) {
            return false;
        }

        return $this->allows('deleteTranslation', $locale) ?? true;
    }

    /**
     * @throws AuthorizationException
     */
    public function authorizeTranslate(string $locale): void
    {
        if (! $this->canTranslate($locale)) {
            throw new AuthorizationException;
        }
    }

    /**
     * The answer of an ability the application defines, or null when it
     * doesn't define it.
     */
    private function allows(string $ability, string $locale): ?bool
    {
        $resource = $this->config->getResource();

        if ($resource !== null && $resource::shouldSkipAuthorization()) {
            return true;
        }

        $policy = Gate::getPolicyFor($this->record);

        if (! Gate::has($ability) && ! ($policy !== null && method_exists($policy, $ability))) {
            return null;
        }

        return Gate::forUser(Filament::auth()->user())->allows($ability, [$this->record, $locale]);
    }

    private function canUpdateRecord(): bool
    {
        $resource = $this->config->getResource();

        if ($resource !== null) {
            return $resource::canEdit($this->record);
        }

        return Gate::forUser(Filament::auth()->user())->allows('update', $this->record);
    }

    /*
    |--------------------------------------------------------------------------
    | Form
    |--------------------------------------------------------------------------
    */

    /**
     * The translatable attributes the form edits, in form order.
     *
     * @return list<string>
     */
    public function attributes(): array
    {
        if ($this->attributes !== null) {
            return $this->attributes;
        }

        $locale = $this->locales()->first() ?? $this->registry()->default();
        $attributes = [];

        $this->eachUnit($this->formComponents($locale), function (Component $unit) use (&$attributes): void {
            $attribute = self::attributeOf($unit);

            if (! $this->record->isTranslatableAttribute($attribute)) {
                throw new LogicException(sprintf(
                    'The translation form field [%s] is not a translatable attribute of [%s]. Translatable attributes: [%s].',
                    $unit->getStatePath(isAbsolute: false),
                    $this->record::class,
                    implode(', ', $this->record->getTranslatableAttributes()),
                ));
            }

            $attributes[$attribute] = true;

            if ($unit instanceof RichEditor && $unit->getStatePath(isAbsolute: false) === $attribute) {
                $this->richTextAttributes[$attribute] = true;
            }
        });

        if ($attributes === []) {
            throw new LogicException(sprintf('The translation form of [%s] has no fields.', $this->record::class));
        }

        return $this->attributes = array_keys($attributes);
    }

    /**
     * Reuse attributes computed for another record of the same form, so
     * tables don't build the form for every row.
     *
     * @param  list<string>  $attributes
     */
    public function useAttributes(array $attributes): self
    {
        $this->attributes = $attributes;

        return $this;
    }

    /**
     * The editor's components, for the host schema.
     *
     * @return array<Component>
     */
    public function components(): array
    {
        $this->attributes();

        $header = [];

        if ($this->config->isComparison()) {
            $header[] = Select::make(self::SOURCE_LOCALE)
                ->label(__('tipi-filament-translations::editor.source.label'))
                ->options(fn (Get $get): array => $this->sourceOptions($this->editing($get))
                    ->map(fn (Locale $locale): string => $locale->name)
                    ->all())
                ->searchable(fn (): bool => $this->sourceOptions([])->count() > TranslationEditor::TABS_LIMIT)
                ->selectablePlaceholder(false)
                ->live()
                ->dehydrated(false)
                ->afterStateUpdated(fn (Select $component, ?string $state, ?string $old) => $this->switchSource($component, $state, $old))
                ->columnSpan(['default' => 'full', '@xl' => 1]);
        }

        $header[] = LocaleNavigation::make(self::LOCALE)
            ->editor($this)
            ->columnSpan(['default' => 'full', '@xl' => $this->config->isComparison() ? 3 : 'full']);

        return [
            Hidden::make(self::LOADED),
            // Sized by the editor's width, so modals and pages behave alike.
            Grid::make(['default' => 1, '@xl' => 4])
                ->gridContainer()
                ->schema($header)
                ->key('tipi-translations-header'),
            Text::make(__('tipi-filament-translations::editor.empty'))
                ->visible(fn (Get $get): bool => $this->targets($get(self::SOURCE_LOCALE))->isEmpty()),
            ...$this->locales()->map(fn (Locale $locale): LocalePanel => LocalePanel::make($this, $locale))->values()->all(),
        ];
    }

    /**
     * The rows of one locale's panel: the form, with each field wrapped so
     * that it is bound to the locale and, in the comparison layout, paired
     * with a read-only copy bound to the source.
     *
     * @return array<mixed>
     */
    public function rows(Locale $locale, ?string $source, bool $withSource): array
    {
        $sourceLocale = ($withSource && $source !== null) ? $this->registry()->find($source) : null;

        return $this->arrange(
            $this->formComponents($locale),
            $sourceLocale === null ? null : $this->formComponents($sourceLocale),
            $locale,
            $sourceLocale,
        );
    }

    /**
     * @param  array<mixed>  $components
     * @param  array<mixed>|null  $twins
     * @return array<mixed>
     */
    private function arrange(array $components, ?array $twins, Locale $locale, ?Locale $source): array
    {
        if ($twins !== null && count($twins) !== count($components)) {
            throw new LogicException('The translation form must build the same components for every locale.');
        }

        $arranged = [];

        foreach (array_values($components) as $index => $component) {
            $twin = $twins === null ? null : array_values($twins)[$index];

            if (! $component instanceof Component) {
                $arranged[] = $component;

                continue;
            }

            if (self::isUnit($component)) {
                $arranged[] = TranslationRow::make($this, $component, $twin instanceof Component ? $twin : null, $locale, $source);

                continue;
            }

            $children = self::layoutChildren($component);

            if ($children !== null) {
                $component->childComponents($this->arrange(
                    $children,
                    $twin instanceof Component ? self::layoutChildren($twin) : null,
                    $locale,
                    $source,
                ));
            }

            $arranged[] = $component;
        }

        return $arranged;
    }

    /**
     * @param  array<mixed>  $components
     * @param  callable(Component): void  $callback
     */
    private function eachUnit(array $components, callable $callback): void
    {
        foreach ($components as $component) {
            if (! $component instanceof Component) {
                continue;
            }

            if (self::isUnit($component)) {
                $callback($component);

                continue;
            }

            $this->eachUnit(self::layoutChildren($component) ?? [], $callback);
        }
    }

    /**
     * A fresh build of the form for the locale.
     *
     * @return array<mixed>
     */
    private function formComponents(Locale $locale): array
    {
        $form = ($this->config->getForm())(Schema::make(), $locale);

        return $form instanceof Schema ? $form->getComponents(withActions: true, withHidden: true) : (array) $form;
    }

    /**
     * Fields, and components with their own state path (which own their
     * children's state), are edited as a whole.
     */
    private static function isUnit(Component $component): bool
    {
        return $component instanceof Field || filled($component->getStatePath(isAbsolute: false));
    }

    /**
     * The children of a layout component, when they are given as a list.
     *
     * @return array<mixed>|null
     */
    private static function layoutChildren(Component $component): ?array
    {
        $children = $component->getDefaultChildComponents();

        return is_array($children) && $children !== [] ? array_values($children) : null;
    }

    public static function attributeOf(Component $unit): string
    {
        return Str::before((string) $unit->getStatePath(isAbsolute: false), '.');
    }

    /*
    |--------------------------------------------------------------------------
    | State
    |--------------------------------------------------------------------------
    */

    /**
     * The state the editor opens with.
     *
     * @return array<string, mixed>
     */
    public function initialState(?string $locale = null, ?string $source = null): array
    {
        if ($this->config->isComparison()) {
            $source = ($source !== null && $this->sourceOptions([])->has($source) && $source !== $locale)
                ? $source
                : $this->defaultSource(except: $locale !== null && $this->locales()->has($locale) ? $locale : null);
        } else {
            $source = null;
        }

        $locale = $this->defaultLocale($locale, $source);

        return [
            self::LOCALE => $locale,
            self::SOURCE_LOCALE => $source,
            self::LOADED => $locale === null ? [] : [$locale => $this->fingerprint($locale)],
            self::TRANSLATIONS => $locale === null ? [] : [$locale => $this->storedValues($locale)],
            self::SOURCE => $source === null ? [] : $this->storedValues($source),
        ];
    }

    /**
     * Exactly what is stored for the locale: attribute => value or null.
     *
     * @return array<string, mixed>
     */
    public function storedValues(string $locale): array
    {
        $values = [];

        foreach ($this->attributes() as $attribute) {
            $values[$attribute] = $this->record->getTranslation($attribute, $locale);
        }

        return $values;
    }

    public function fingerprint(string $locale): string
    {
        return md5(serialize($this->storedValues($locale)));
    }

    /**
     * Load a locale into the editor the first time it is opened.
     */
    public function loadLocale(Schema $root, string $locale): void
    {
        $loaded = $this->loaded($root->getRawState());
        $loaded[$locale] = $this->fingerprint($locale);

        $root->fillPartially(
            [self::TRANSLATIONS => [$locale => $this->storedValues($locale)], self::LOADED => $loaded],
            [self::TRANSLATIONS.'.'.$locale, self::LOADED],
        );
    }

    /**
     * Replace one loaded locale with what is stored now.
     */
    public function reloadLocale(Schema $root, string $locale): void
    {
        $this->flush();
        $this->loadLocale($root, $locale);
    }

    public function loadSource(Schema $root, string $source): void
    {
        $root->fillPartially([self::SOURCE => $this->storedValues($source)], [self::SOURCE]);
    }

    /**
     * Copy the stored source value of a field into the target.
     */
    public function copySource(Schema $root, Locale $locale, string $statePath, ?string $source): void
    {
        if ($source === null) {
            return;
        }

        $this->authorizeTranslate($locale->code);

        $attribute = Str::before($statePath, '.');
        $value = data_get([$attribute => $this->record->getTranslation($attribute, $source)], $statePath);

        $root->fillPartially(
            [self::TRANSLATIONS => [$locale->code => Arr::undot([$statePath => $value])]],
            [self::TRANSLATIONS.'.'.$locale->code.'.'.$statePath],
        );
    }

    public function switchLocale(Component $navigation, ?string $locale, ?string $previous): void
    {
        $state = $navigation->getRootContainer()->getRawState();

        if ($locale === null || ! $this->targets($state[self::SOURCE_LOCALE] ?? null)->has($locale)) {
            $navigation->state($previous);

            return;
        }

        if (! array_key_exists($locale, $this->loaded($state))) {
            $this->loadLocale($navigation->getRootContainer(), $locale);
        }
    }

    public function switchSource(Component $select, ?string $source, ?string $previous): void
    {
        $root = $select->getRootContainer();

        if ($source === null || ! $this->sourceOptions(array_keys($this->loaded($root->getRawState())))->has($source)) {
            $select->state($previous);

            return;
        }

        $this->loadSource($root, $source);
    }

    /**
     * @param  array<string, mixed>|object  $state
     * @return array<string, string>
     */
    public function loaded(array|object $state): array
    {
        $loaded = data_get($state, self::LOADED);

        return is_array($loaded) ? array_filter($loaded, is_string(...)) : [];
    }

    /**
     * The locales being edited: they can't be the source.
     *
     * @return list<string>
     */
    private function editing(Get $get): array
    {
        $loaded = $get(self::LOADED);

        return is_array($loaded) ? array_map(strval(...), array_keys($loaded)) : [];
    }

    /*
    |--------------------------------------------------------------------------
    | Saving
    |--------------------------------------------------------------------------
    */

    /**
     * Save the loaded locales of the editor's dehydrated state.
     *
     * @param  array<string, mixed>  $state
     * @return list<string> the locales that changed
     *
     * @throws AuthorizationException
     * @throws TranslationConflictException
     */
    public function save(array $state): array
    {
        $translations = is_array($state[self::TRANSLATIONS] ?? null) ? $state[self::TRANSLATIONS] : [];
        $attributes = array_flip($this->attributes());
        $changes = [];

        foreach ($this->loaded($state) as $locale => $fingerprint) {
            $locale = (string) $locale;

            $this->authorizeTranslate($locale);

            // A locale that isn't shown (the source) has no dehydrated values.
            if (! is_array($translations[$locale] ?? null)) {
                continue;
            }

            $values = array_intersect_key($translations[$locale], $attributes);

            foreach ($values as $attribute => $value) {
                $values[$attribute] = $this->normalize($attribute, $value);
            }

            $changes[$locale] = [$fingerprint, $values];
        }

        if ($changes === []) {
            return [];
        }

        $record = $this->record;

        $changed = $record->getConnection()->transaction(function () use ($record, $changes): array {
            // Lock the row and read what other saves committed meanwhile.
            $record->newQueryWithoutScopes()->whereKey($record->getKey())->lockForUpdate()->first();
            $record->refresh();

            $conflicts = array_keys(array_filter(
                $changes,
                fn (array $change, string $locale): bool => ! hash_equals($this->fingerprint($locale), $change[0]),
                ARRAY_FILTER_USE_BOTH,
            ));

            if ($conflicts !== []) {
                throw new TranslationConflictException($conflicts);
            }

            foreach ($changes as $locale => [, $values]) {
                $record->setTranslations($locale, $values);
            }

            $changed = array_keys($record->getDirtyTranslations());

            $record->save();

            return $changed;
        });

        $this->flush();

        return $changed;
    }

    /**
     * Empty form values mean "no translation": they remove the value, so
     * readers see the fallback instead of an empty text. A rich editor left
     * empty produces an empty paragraph, which is empty too.
     */
    private function normalize(string $attribute, mixed $value): mixed
    {
        if ($value === '' || $value === []) {
            return null;
        }

        if (isset($this->richTextAttributes[$attribute]) && is_string($value) && self::isBlankHtml($value)) {
            return null;
        }

        return $value;
    }

    private static function isBlankHtml(string $html): bool
    {
        $text = strip_tags($html, '<img><video><audio><iframe><embed><object><hr>');
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(str_replace("\u{00A0}", ' ', $text)) === '';
    }

    /**
     * Remove every value of a locale.
     *
     * @throws AuthorizationException
     */
    public function deleteTranslation(string $locale): void
    {
        if (! $this->canDelete($locale)) {
            throw new AuthorizationException;
        }

        $this->record->forgetTranslations($locale)->save();
        $this->flush();
    }

    /*
    |--------------------------------------------------------------------------
    | Translation states
    |--------------------------------------------------------------------------
    */

    public function supportsStates(): bool
    {
        return TranslationProgress::supportsStates($this->record);
    }

    /**
     * @throws AuthorizationException
     */
    public function markCurrent(string $locale): void
    {
        $this->authorizeTranslate($locale);
        $this->record->markTranslationCurrent($locale);
        $this->flush();
    }

    /**
     * @throws AuthorizationException
     */
    public function markOutdated(string $locale): void
    {
        $this->authorizeTranslate($locale);
        $this->record->markTranslationOutdated($locale);
        $this->flush();
    }

    /**
     * @throws AuthorizationException
     */
    public function markOthersOutdated(string $locale): void
    {
        $this->authorizeTranslate($locale);
        $this->record->markOtherTranslationsOutdated($locale);
        $this->flush();
    }

    /**
     * @throws AuthorizationException
     */
    public function setWorkflowStatus(string $locale, ?BackedEnum $status): void
    {
        $this->authorizeTranslate($locale);
        $this->record->setTranslationStatus($locale, $status);
        $this->flush();
    }

    /*
    |--------------------------------------------------------------------------
    | Status
    |--------------------------------------------------------------------------
    */

    public function progress(): TranslationProgress
    {
        return $this->progress ??= TranslationProgress::of($this->record, $this->attributes(), $this->offeredLocales());
    }

    public function localeProgress(string $locale): LocaleProgress
    {
        return $this->progress()->locale($locale)
            ?? TranslationProgress::of($this->record, $this->attributes(), $this->registry()->all()->only([$locale]))->locale($locale);
    }

    /**
     * The stored state of an attribute in a locale, and the locale readers
     * of the content context see instead when it falls back.
     *
     * @return array{FieldStatus, Locale|null}
     */
    public function fieldStatus(string $attribute, string $locale): array
    {
        if ($this->record->hasTranslation($attribute, $locale)) {
            return [FieldStatus::Translated, null];
        }

        $resolved = app(ContentContext::class)->run(
            $this->context,
            fn () => $this->record->resolveTranslation($attribute, $locale),
        );

        return $resolved->isFallback()
            ? [FieldStatus::Fallback, $this->registry()->find((string) $resolved->locale)]
            : [FieldStatus::Missing, null];
    }

    public function flush(): void
    {
        $this->progress = null;
    }

    /*
    |--------------------------------------------------------------------------
    | Resource integration
    |--------------------------------------------------------------------------
    */

    public function recordTitle(): string
    {
        $resource = $this->config->getResource();

        $title = $resource === null ? null : $resource::getRecordTitle($this->record);

        return filled($title) ? strip_tags((string) $title) : (string) $this->record->getKey();
    }
}
