<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Editor;

use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tipi\Localization\LocaleContext;
use Tipi\Localization\LocaleRegistry;
use Tipi\Support\Locale;
use Tipi\Translations\Concerns\HasTranslationStates;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Filament\Content\Alignment;
use Tipi\Translations\Filament\Content\ContentSchema;
use Tipi\Translations\Filament\Content\InvalidTranslation;
use Tipi\Translations\Filament\Content\Nodes\TextNode;
use Tipi\Translations\Filament\Content\RecordContent;
use Tipi\Translations\Filament\Content\Segment;
use Tipi\Translations\Filament\Content\SegmentText;
use Tipi\Translations\Filament\Editor\Components\LocaleNavigation;
use Tipi\Translations\Filament\Editor\Components\LocalePanel;
use Tipi\Translations\Filament\Exceptions\TranslationConflictException;
use Tipi\Translations\Filament\Support\ContentContext;
use Tipi\Translations\Filament\Support\LocaleProgress;
use Tipi\Translations\Filament\Support\TranslationProgress;
use Tipi\Translations\Filament\TranslationEditor;

/**
 * The translation editor of one record: the engine behind every
 * presentation (page, modal, slide-over) and layout (comparison,
 * locale-focused).
 *
 * Translators edit text, never structure. The canonical source locale
 * decides what there is to translate: each text of its content is a
 * segment, edited in a plain textarea; formatting and links are tags
 * the translator places. Saving rebuilds each changed attribute from the
 * source's structure and the translated texts.
 *
 * Editor state, relative to the host schema:
 *
 *     locale        the target locale shown
 *     reference     the locale shown beside it (comparison layout)
 *     loaded        locale => what the editor loaded, to tell changes and
 *                   conflicts: stored value fingerprints by attribute,
 *                   source structure by attribute, segment => source
 *                   fingerprint, and the loaded texts ("base")
 *     translations  locale => segment key => text, for loaded locales
 *     conflicts     locale => what the last save ran into
 *
 * Contract:
 * - Segments show exactly what is stored for their locale, never fallback
 *   text. Blank means not translated; saving it stores no text.
 * - Saving writes every loaded locale with changed segments, in one locked
 *   transaction (lockTranslationsForUpdate()). Changes of others to other
 *   segments are merged; a segment changed by someone else, a segment
 *   whose source text changed, or an attribute whose source structure
 *   changed since loading is a conflict: nothing is saved, the
 *   translator's text is kept and the conflict is shown.
 * - Drafts are fine: completeness is reported, never required.
 * - Locale codes, segment keys and texts in the state are never trusted:
 *   only offered, authorized locales and the source's segments are
 *   written, checked again on the server.
 *
 * @internal
 */
final class RecordEditor
{
    public const string LOCALE = 'locale';

    public const string REFERENCE = 'reference';

    public const string LOADED = 'loaded';

    public const string TRANSLATIONS = 'translations';

    public const string CONFLICTS = 'conflicts';

    public const string REVIEWED = 'reviewed';

    public readonly LocaleContext $context;

    /**
     * @var Collection<string, Locale>|null
     */
    private ?Collection $offeredLocales = null;

    /**
     * @var Collection<string, Locale>|null
     */
    private ?Collection $locales = null;

    private ?ContentSchema $schema = null;

    /**
     * @var array<string, ContentSchema> by locale, for locale-specific limits
     */
    private array $localeSchemas = [];

    /**
     * @var array<string, array<string, Segment>> locale => key => segment
     */
    private array $localeSegments = [];

    private ?RecordContent $content = null;

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
     * The canonical source: the language content is authored in. It decides
     * what there is to translate. The global default locale unless the
     * editor configures another one.
     */
    public function sourceLocale(): Locale
    {
        $configured = $this->config->getSourceLocale($this->record);

        return $configured === null ? $this->registry()->default() : $this->registry()->get($configured);
    }

    /**
     * The locales the user may translate into, in display order.
     *
     * @return Collection<string, Locale>
     */
    public function locales(): Collection
    {
        return $this->locales ??= $this->offeredLocales()
            ->except($this->sourceLocale()->code)
            ->filter(fn (Locale $locale): bool => $this->canTranslate($locale->code));
    }

    /**
     * Locales that can be shown beside a target: the source, and every
     * other offered or stored locale.
     *
     * @return Collection<string, Locale>
     */
    public function referenceOptions(?string $target): Collection
    {
        $stored = $this->record->getTranslatedLocales();
        $offered = $this->offeredLocales();
        $source = $this->sourceLocale()->code;

        return $this->registry()->all()->filter(
            fn (Locale $locale): bool => $locale->code !== $target
                && ($locale->code === $source || $offered->has($locale->code) || in_array($locale->code, $stored, true)),
        );
    }

    public function defaultReference(?string $target): string
    {
        return $this->sourceLocale()->code;
    }

    public function defaultLocale(?string $requested): ?string
    {
        $targets = $this->locales();

        if ($requested !== null && $targets->has($requested)) {
            return $requested;
        }

        if (! $this->config->isComparison() && $targets->has($this->context->defaultCode())) {
            return $this->context->defaultCode();
        }

        $needsWork = $targets->first(fn (Locale $locale): bool => $this->localeProgress($locale->code)->needsWork());

        return ($needsWork ?? $targets->first())?->code;
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
        if (! $this->offeredLocales()->has($locale) || $locale === $this->sourceLocale()->code) {
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
    | Content
    |--------------------------------------------------------------------------
    */

    /**
     * The translatable shape of the record, read from the translation form.
     */
    public function schema(): ContentSchema
    {
        return $this->schema ??= $this->buildSchema($this->locales()->first() ?? $this->sourceLocale());
    }

    /**
     * Reuse the schema built for another record of the same form, so tables
     * don't read the form for every row.
     */
    public function useSchema(ContentSchema $schema): self
    {
        $this->schema = $schema;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function attributes(): array
    {
        return $this->schema()->attributes();
    }

    public function content(): RecordContent
    {
        return $this->content ??= new RecordContent($this->record, $this->schema(), $this->sourceLocale()->code);
    }

    /**
     * The source's segments with the limits of the target locale's form
     * (a field's maxLength may depend on the locale).
     *
     * @return array<string, Segment> key => segment
     */
    public function segments(string $locale): array
    {
        if (isset($this->localeSegments[$locale])) {
            return $this->localeSegments[$locale];
        }

        $content = $this->content();
        $target = $this->registry()->find($locale);
        $schema = $target === null ? $this->schema() : ($this->localeSchemas[$locale] ??= $this->buildSchema($target));
        $segments = [];

        foreach ($content->segments() as $attribute => $sourceSegments) {
            $attributeSegments = $schema === $this->schema() || $sourceSegments === []
                ? $sourceSegments
                : $schema->segments($attribute, $content->sourceValue($attribute));

            foreach ($attributeSegments as $segment) {
                $segments[$segment->key()] = $segment;
            }
        }

        return $this->localeSegments[$locale] = $segments;
    }

    /**
     * @return array<string, list<Segment>> attribute => segments
     */
    public function segmentsByAttribute(string $locale): array
    {
        $grouped = array_fill_keys($this->attributes(), []);

        foreach ($this->segments($locale) as $segment) {
            $grouped[$segment->attribute][] = $segment;
        }

        return $grouped;
    }

    public function alignment(string $locale, string $attribute): Alignment
    {
        return $this->content()->alignment($locale, $attribute);
    }

    /**
     * The text of a segment in a reference locale.
     */
    public function referenceText(string $locale, Segment $segment): ?string
    {
        return $this->alignment($locale, $segment->attribute)->texts[$segment->path] ?? null;
    }

    /**
     * @return list<string> regular expressions
     */
    public function placeholders(): array
    {
        return $this->config->getPlaceholders();
    }

    private function buildSchema(Locale $locale): ContentSchema
    {
        $form = ($this->config->getForm())(Schema::make(), $locale);
        $components = $form instanceof Schema ? $form->getComponents(withActions: true, withHidden: true) : (array) $form;

        $schema = ContentSchema::fromComponents(
            $components,
            fn (string $attribute): bool => $this->record->isTranslatableAttribute($attribute),
            $this->config->getItemKey(),
            $this->config->getCustomBlocks(),
        );

        // A field with several texts can be partly translated: that work is
        // kept as an unpublished draft, which needs translation states.
        if (! $this->supportsStates()) {
            foreach ($schema->attributes() as $attribute) {
                if (! $schema->node($attribute) instanceof TextNode) {
                    throw new LogicException(sprintf(
                        'The translation form field [%s] has several texts, so a partial translation is saved as a draft until it is complete. Add the [%s] trait to [%s] (and run its migration).',
                        $attribute,
                        HasTranslationStates::class,
                        $this->record::class,
                    ));
                }
            }
        }

        return $schema;
    }

    /*
    |--------------------------------------------------------------------------
    | Components
    |--------------------------------------------------------------------------
    */

    /**
     * The editor's components, for the host schema.
     *
     * @return array<Component>
     */
    public function components(): array
    {
        $this->schema();

        $header = [];

        if ($this->config->isComparison()) {
            $header[] = Select::make(self::REFERENCE)
                ->label(__('tipi-filament-translations::editor.reference.label'))
                ->options(fn (Get $get): array => $this->referenceOptions($get(self::LOCALE))
                    ->map(fn (Locale $locale): string => $locale->code === $this->sourceLocale()->code
                        ? __('tipi-filament-translations::editor.reference.source', ['locale' => $locale->name])
                        : $locale->name)
                    ->all())
                ->searchable(fn (): bool => $this->referenceOptions(null)->count() > TranslationEditor::TABS_LIMIT)
                ->selectablePlaceholder(false)
                ->live()
                ->dehydrated(false)
                ->validatedWhenNotDehydrated(false)
                ->afterStateUpdated(fn (Select $component, ?string $state, ?string $old) => $this->switchReference($component, $state, $old))
                ->columnSpan(['default' => 'full', '@xl' => 1]);
        }

        $header[] = LocaleNavigation::make(self::LOCALE)
            ->editor($this)
            ->columnSpan(['default' => 'full', '@xl' => $this->config->isComparison() ? 3 : 'full']);

        return [
            Hidden::make(self::LOADED),
            Hidden::make(self::CONFLICTS),
            Hidden::make(self::REVIEWED),
            // Sized by the editor's width, so modals and pages behave alike.
            Grid::make(['default' => 1, '@xl' => 4])
                ->gridContainer()
                ->schema($header)
                ->key('tipi-translations-header'),
            Text::make(__('tipi-filament-translations::editor.empty'))
                ->visible(fn (): bool => $this->locales()->isEmpty()),
            ...$this->locales()->map(fn (Locale $locale): LocalePanel => LocalePanel::make($this, $locale))->values()->all(),
        ];
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
    public function initialState(?string $locale = null, ?string $reference = null): array
    {
        $locale = $this->defaultLocale($locale);

        if ($this->config->isComparison()) {
            $reference = $reference !== null && $this->referenceOptions($locale)->has($reference) ? $reference : $this->defaultReference($locale);
        } else {
            $reference = null;
        }

        return [
            self::LOCALE => $locale,
            self::REFERENCE => $reference,
            self::LOADED => $locale === null ? [] : [$locale => $this->entry($locale)],
            self::TRANSLATIONS => $locale === null ? [] : [$locale => $this->storedTexts($locale)],
            self::CONFLICTS => [],
            self::REVIEWED => [],
        ];
    }

    /**
     * The stored texts of every segment of the locale, null when missing.
     *
     * @return array<string, string|null>
     */
    public function storedTexts(string $locale): array
    {
        $stored = $this->content()->texts($locale);
        $texts = [];

        foreach (array_keys($this->segments($locale)) as $key) {
            $texts[$key] = $stored[$key] ?? null;
        }

        return $texts;
    }

    /**
     * What the editor remembers of a locale it loads: the stored values'
     * fingerprints, the source's structure and segment fingerprints, and
     * the loaded texts.
     *
     * @return array{stored: array<string, string>, structure: array<string, array<string, string>>, segments: array<string, array{0: string, 1: string}>, base: array<string, string|null>}
     */
    public function entry(string $locale): array
    {
        $content = $this->content();
        $stored = [];
        $structure = [];

        foreach ($this->attributes() as $attribute) {
            $stored[$attribute] = $this->storedFingerprint($locale, $attribute);
            $structure[$attribute] = $this->anchors($attribute);
        }

        return [
            'stored' => $stored,
            'structure' => $structure,
            'segments' => array_map(fn (Segment $segment): array => [$segment->attribute, $segment->hash()], $this->segments($locale)),
            'base' => $this->storedTexts($locale),
        ];
    }

    private static function fingerprint(mixed $value): string
    {
        return md5(serialize($value));
    }

    /**
     * The stored published value and draft of an attribute.
     */
    private function storedFingerprint(string $locale, string $attribute): string
    {
        return self::fingerprint([$this->record->getTranslation($attribute, $locale), $this->content()->draft($locale, $attribute)]);
    }

    /**
     * What the attribute's segment paths depend on (see Node::anchors()).
     *
     * @return array<string, string>
     */
    private function anchors(string $attribute): array
    {
        $content = $this->content();

        if ($content->isUnsupported($attribute)) {
            return ['' => 'unsupported'];
        }

        return ['' => 'supported', ...$this->schema()->node($attribute)->anchors('', $content->sourceValue($attribute))];
    }

    /**
     * Whether the anchors both sides have are equal: segment paths kept
     * their meaning. Items added or removed don't matter.
     *
     * @param  array<string, string>  $before
     * @param  array<string, string>  $now
     */
    private static function sameAnchors(array $before, array $now): bool
    {
        foreach (array_intersect_key($before, $now) as $path => $anchor) {
            if ($now[$path] !== $anchor) {
                return false;
            }
        }

        return ($before[''] ?? null) === ($now[''] ?? null);
    }

    /**
     * Segments the translator confirmed as still correct for their changed
     * source text.
     *
     * @param  array<string, mixed>|object  $state
     * @return list<string>
     */
    public function reviewed(array|object $state, string $locale): array
    {
        $reviewed = data_get($state, self::REVIEWED.'.'.$locale);

        return is_array($reviewed) ? array_map('strval', array_keys(array_filter($reviewed))) : [];
    }

    /**
     * Load a locale into the editor the first time it is opened.
     */
    public function loadLocale(Schema $root, string $locale): void
    {
        $loaded = $this->loaded($root->getRawState());
        $loaded[$locale] = $this->entry($locale);

        $root->fillPartially(
            [self::TRANSLATIONS => [$locale => $this->storedTexts($locale)], self::LOADED => $loaded],
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
        $this->clearConflicts($root, $locale);
    }

    /**
     * The segments the editor changed in a loaded locale: key => text.
     *
     * @param  array<string, mixed>|object  $state
     * @return array<string, string|null>
     */
    public function changes(array|object $state, string $locale): array
    {
        $base = $this->loaded($state)[$locale]['base'] ?? null;
        $texts = data_get($state, self::TRANSLATIONS.'.'.$locale);

        if (! is_array($base) || ! is_array($texts)) {
            return [];
        }

        $changes = [];

        foreach ($texts as $key => $text) {
            $key = (string) $key;

            if (! array_key_exists($key, $base) || ($text !== null && ! is_string($text))) {
                continue;
            }

            if (self::loose($text) !== self::loose($base[$key])) {
                $changes[$key] = $text;
            }
        }

        return $changes;
    }

    public function isChanged(Schema $root, string $locale): bool
    {
        return $this->changes($root->getRawState(), $locale) !== [] || $this->reviewed($root->getRawState(), $locale) !== [];
    }

    private static function loose(mixed $text): ?string
    {
        if (! is_string($text)) {
            return null;
        }

        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));

        return $text === '' ? null : $text;
    }

    /**
     * Put the reference's text of a segment into the target.
     */
    public function copyReference(Schema $root, string $locale, string $key): void
    {
        $this->authorizeTranslate($locale);

        $segment = $this->segments($locale)[$key] ?? null;
        $reference = $root->getRawState()[self::REFERENCE] ?? null;

        if ($segment === null) {
            return;
        }

        $text = is_string($reference) && $this->referenceOptions($locale)->has($reference)
            ? $this->referenceText($reference, $segment)
            : $segment->text;

        $this->fill($root, $locale, $key, $text ?? $segment->text);
    }

    /**
     * Take the text someone else saved for a conflicting segment.
     */
    public function useTheirs(Schema $root, string $locale, string $key): void
    {
        $this->authorizeTranslate($locale);

        $conflict = $this->conflicts($root->getRawState(), $locale)['segments'][$key] ?? null;

        if (! is_array($conflict)) {
            return;
        }

        $this->fill($root, $locale, $key, is_string($conflict['theirs'] ?? null) ? $conflict['theirs'] : null);

        $conflicts = $this->conflicts($root->getRawState(), $locale);
        unset($conflicts['segments'][$key]);
        $this->setConflicts($root, $locale, $conflicts);
    }

    private function fill(Schema $root, string $locale, string $key, ?string $text): void
    {
        $root->fillPartially(
            [self::TRANSLATIONS => [$locale => [$key => $text]]],
            [self::TRANSLATIONS.'.'.$locale.'.'.$key],
        );
    }

    public function switchLocale(Component $navigation, ?string $locale, ?string $previous): void
    {
        $root = $navigation->getRootContainer();

        if ($locale === null || ! $this->locales()->has($locale)) {
            $navigation->state($previous);

            return;
        }

        if (! array_key_exists($locale, $this->loaded($root->getRawState()))) {
            $this->loadLocale($root, $locale);
        }

        $reference = $root->getRawState()[self::REFERENCE] ?? null;

        if ($this->config->isComparison() && (! is_string($reference) || ! $this->referenceOptions($locale)->has($reference))) {
            $root->fillPartially([self::REFERENCE => $this->defaultReference($locale)], [self::REFERENCE]);
        }
    }

    public function switchReference(Component $select, ?string $reference, ?string $previous): void
    {
        $root = $select->getRootContainer();

        if ($reference === null || ! $this->referenceOptions($root->getRawState()[self::LOCALE] ?? null)->has($reference)) {
            $select->state($previous);
        }
    }

    /**
     * @param  array<string, mixed>|object  $state
     * @return array<string, array<string, mixed>>
     */
    public function loaded(array|object $state): array
    {
        $loaded = data_get($state, self::LOADED);

        if (! is_array($loaded)) {
            return [];
        }

        return array_filter(
            $loaded,
            fn (mixed $entry): bool => is_array($entry) && is_array($entry['base'] ?? null) && is_array($entry['segments'] ?? null)
                && is_array($entry['stored'] ?? null) && is_array($entry['structure'] ?? null),
        );
    }

    /**
     * What the last save ran into in a locale: segment key => conflict, and
     * attribute => the texts that no longer fit its source.
     *
     * @param  array<string, mixed>|object  $state
     * @return array{segments: array<string, array{reason: string, theirs: string|null}>, attributes: array<string, list<string>>}
     */
    public function conflicts(array|object $state, string $locale): array
    {
        $conflicts = data_get($state, self::CONFLICTS.'.'.$locale);

        return [
            'segments' => is_array($conflicts['segments'] ?? null) ? $conflicts['segments'] : [],
            'attributes' => is_array($conflicts['attributes'] ?? null) ? $conflicts['attributes'] : [],
        ];
    }

    /**
     * @param  array{segments: array<string, mixed>, attributes: array<string, mixed>}  $conflicts
     */
    private function setConflicts(Schema $root, string $locale, array $conflicts): void
    {
        $all = data_get($root->getRawState(), self::CONFLICTS);
        $all = is_array($all) ? $all : [];
        $all[$locale] = $conflicts;

        $root->partialRawState([self::CONFLICTS => $all]);
    }

    private function clearConflicts(Schema $root, string $locale): void
    {
        $this->setConflicts($root, $locale, ['segments' => [], 'attributes' => []]);
    }

    private function clearReviewed(Schema $root, string $locale): void
    {
        $reviewed = data_get($root->getRawState(), self::REVIEWED);
        $reviewed = is_array($reviewed) ? $reviewed : [];
        unset($reviewed[$locale]);

        $root->partialRawState([self::REVIEWED => $reviewed]);
    }

    /*
    |--------------------------------------------------------------------------
    | Saving
    |--------------------------------------------------------------------------
    */

    /**
     * Save the changed segments of every loaded locale.
     *
     * @return list<string> the locales whose stored values changed
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws TranslationConflictException
     */
    public function save(Schema $root): array
    {
        $state = $root->getRawState();
        $loaded = $this->loaded($state);
        $pending = [];
        $reviewed = [];
        $errors = [];

        foreach ($loaded as $locale => $entry) {
            $locale = (string) $locale;

            $this->authorizeTranslate($locale);

            $segments = $this->segments($locale);

            $changes = $this->changes($state, $locale);

            // Confirmed as still correct: saved with its source fingerprint.
            foreach ($this->reviewed($state, $locale) as $key) {
                if (isset($segments[$key]) && ! array_key_exists($key, $changes)) {
                    $changes[$key] = data_get($state, self::TRANSLATIONS.'.'.$locale.'.'.$key);
                }

                $reviewed[$locale][$key] = true;
            }

            foreach ($changes as $key => $text) {
                $segment = $segments[$key] ?? null;

                try {
                    // A segment the source no longer has is a conflict below.
                    $pending[$locale][$key] = $segment === null ? self::loose($text) : SegmentText::read($segment, $text, $this->placeholders());
                } catch (InvalidTranslation $exception) {
                    $errors[$this->statePath($root, $locale, $key)] = $exception->getMessage();
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if ($pending === []) {
            return [];
        }

        try {
            $changed = $this->record->lockTranslationsForUpdate(fn (): array => $this->write($pending, $loaded, $reviewed));
        } catch (TranslationConflictException $exception) {
            // The record holds what is stored now: show it with the conflicts.
            $this->flush();
            $this->recover($root, $pending, $exception);

            throw $exception;
        } catch (InvalidTranslation $exception) {
            $this->flush();

            throw ValidationException::withMessages([
                $this->statePath($root, (string) $exception->locale, (string) $exception->key) => $exception->getMessage(),
            ]);
        }

        $this->flush();

        foreach (array_keys($pending) as $locale) {
            $loadedNow = $this->loaded($root->getRawState());
            $loadedNow[$locale] = $this->entry($locale);

            $root->fillPartially(
                [self::TRANSLATIONS => [$locale => $this->storedTexts($locale)], self::LOADED => $loadedNow],
                [self::TRANSLATIONS.'.'.$locale, self::LOADED],
            );

            $this->clearConflicts($root, $locale);
            $this->clearReviewed($root, $locale);
        }

        return $changed;
    }

    /**
     * Inside the lock: merge the changes into what is stored now and set
     * them on the record, which lockTranslationsForUpdate() then saves.
     *
     * Complete attributes are published (their draft forgotten); partly
     * translated ones are saved as drafts, so readers keep seeing the
     * published translation or the fallback, never gaps.
     *
     * @param  array<string, array<string, string|null>>  $pending
     * @param  array<string, array<string, mixed>>  $loaded
     * @param  array<string, array<string, true>>  $reviewed
     * @return list<string>
     *
     * @throws TranslationConflictException
     * @throws InvalidTranslation
     */
    private function write(array $pending, array $loaded, array $reviewed): array
    {
        // The record was read again: start from what is stored now.
        $this->flush();

        $content = $this->content();
        $conflicts = [];
        $writes = [];

        foreach ($pending as $locale => $changes) {
            $entry = $loaded[$locale];
            $segments = $this->segments($locale);
            $byAttribute = [];

            foreach ($changes as $key => $text) {
                $attribute = $entry['segments'][$key][0] ?? null;

                if (is_string($attribute) && in_array($attribute, $this->attributes(), true)) {
                    $byAttribute[$attribute][$key] = $text;
                }
            }

            foreach ($byAttribute as $attribute => $attributeChanges) {
                $before = $entry['structure'][$attribute] ?? null;

                if (! is_array($before) || ! self::sameAnchors($before, $this->anchors($attribute))) {
                    $conflicts[] = ['locale' => $locale, 'attribute' => $attribute, 'key' => null, 'reason' => TranslationConflictException::STRUCTURE, 'theirs' => null];

                    continue;
                }

                $remote = $content->texts($locale, $attribute);
                $changedRemotely = $this->storedFingerprint($locale, $attribute) !== ($entry['stored'][$attribute] ?? null);
                $attributeKeys = array_filter($segments, fn (Segment $segment): bool => $segment->attribute === $attribute);
                $texts = $changedRemotely ? $remote : array_intersect_key($entry['base'], $attributeKeys);

                foreach ($attributeChanges as $key => $text) {
                    $segment = $segments[$key] ?? null;

                    if ($segment === null || $segment->hash() !== ($entry['segments'][$key][1] ?? null)) {
                        $conflicts[] = ['locale' => $locale, 'attribute' => $attribute, 'key' => $key, 'reason' => TranslationConflictException::SOURCE, 'theirs' => $remote[$key] ?? null];

                        continue;
                    }

                    $theirs = $remote[$key] ?? null;
                    $base = $entry['base'][$key] ?? null;

                    if ($changedRemotely && self::loose($theirs) !== self::loose($base) && self::loose($theirs) !== $text) {
                        $conflicts[] = ['locale' => $locale, 'attribute' => $attribute, 'key' => $key, 'reason' => TranslationConflictException::CHANGED, 'theirs' => $theirs];

                        continue;
                    }

                    $texts[$key] = $text;
                }

                $writes[$locale][$attribute] = $texts;
            }
        }

        if ($conflicts !== []) {
            throw new TranslationConflictException($conflicts);
        }

        $changed = [];

        foreach ($writes as $locale => $attributes) {
            $segments = $this->segments($locale);

            foreach ($attributes as $attribute => $texts) {
                $byPath = [];
                $attributeSegments = array_filter($segments, fn (Segment $segment): bool => $segment->attribute === $attribute);

                foreach ($texts as $key => $text) {
                    if (isset($attributeSegments[$key]) && is_string($text) && trim($text) !== '') {
                        $byPath[$attributeSegments[$key]->path] = $text;
                    }
                }

                try {
                    $value = $byPath === [] ? null : $this->schema()->node($attribute)->rebuild('', $content->sourceValue($attribute), $byPath);
                } catch (InvalidTranslation $exception) {
                    $exception->locale = $locale;
                    $exception->key = Segment::keyOf($exception->path === null || $exception->path === '' ? $attribute : $attribute.'/'.$exception->path);

                    throw $exception;
                }

                $before = $this->storedFingerprint($locale, $attribute);
                $complete = count($byPath) === count($attributeSegments);

                if (! $this->supportsStates()) {
                    // Only single texts here: translated or not.
                    $this->record->setTranslation($attribute, $locale, $value);
                } elseif ($value === null) {
                    // Cleared: the draft, if there is one, or else the translation.
                    $content->draft($locale, $attribute) !== null
                        ? $this->record->forgetTranslationDraft($attribute, $locale)
                        : $this->record->setTranslation($attribute, $locale, null);
                } elseif ($complete) {
                    $this->record->setTranslation($attribute, $locale, $value)->forgetTranslationDraft($attribute, $locale);
                } else {
                    $this->record->setTranslationDraft($attribute, $locale, $value);
                }

                if ($this->supportsStates()) {
                    $sources = $this->sourcesAfter($locale, $attribute, $attributeSegments, $byPath, $pending[$locale], $reviewed[$locale] ?? []);

                    // Confirming texts as still correct is a change too.
                    if (($sources ?? []) !== $content->sources($locale, $attribute)) {
                        $changed[$locale] = true;
                    }

                    $this->record->setTranslationSource($attribute, $locale, $sources);
                }

                if ($this->recordFingerprint($locale, $attribute) !== $before) {
                    $changed[$locale] = true;
                }
            }
        }

        return array_map('strval', array_keys($changed));
    }

    /**
     * The fingerprint of the pending published value and draft.
     */
    private function recordFingerprint(string $locale, string $attribute): string
    {
        $draft = $this->supportsStates() ? ($this->record->getDirtyTranslationStates()[$locale]['drafts'] ?? []) : [];

        return self::fingerprint([
            $this->record->getTranslation($attribute, $locale),
            array_key_exists($attribute, $draft) ? $draft[$attribute] : $this->content()->draft($locale, $attribute),
        ]);
    }

    /**
     * What each translated segment was made from after this save: the
     * current source text for segments the translator wrote or confirmed;
     * what was recorded before for the others, so a stale translation never
     * looks current because something else was saved.
     *
     * @param  array<string, Segment>  $segments  the attribute's, by key
     * @param  array<string, string>  $texts  translated texts by path
     * @param  array<string, string|null>  $touched  changed segments by key
     * @param  array<string, true>  $reviewed
     * @return array<string, string>|null
     */
    private function sourcesAfter(string $locale, string $attribute, array $segments, array $texts, array $touched, array $reviewed): ?array
    {
        $before = $this->content()->sources($locale, $attribute);
        $sources = [];

        foreach ($segments as $key => $segment) {
            if (! isset($texts[$segment->path])) {
                continue;
            }

            if (array_key_exists($key, $touched) || isset($reviewed[$key])) {
                $sources[$segment->path] = $segment->hash();
            } elseif (isset($before[$segment->path])) {
                $sources[$segment->path] = $before[$segment->path];
            }
        }

        return $sources === [] ? null : $sources;
    }

    /**
     * After a conflict: load what is stored now, keep the translator's texts
     * on top, and show what conflicted.
     *
     * @param  array<string, array<string, string|null>>  $pending
     */
    private function recover(Schema $root, array $pending, TranslationConflictException $exception): void
    {
        $loaded = $this->loaded($root->getRawState());

        foreach ($pending as $locale => $changes) {
            $previous = $loaded[$locale];
            $loaded[$locale] = $this->entry($locale);
            $texts = $this->storedTexts($locale);
            $conflicts = ['segments' => [], 'attributes' => []];

            $structural = [];

            foreach ($exception->conflicts as $conflict) {
                if ($conflict['locale'] !== $locale) {
                    continue;
                }

                if ($conflict['reason'] === TranslationConflictException::STRUCTURE) {
                    $structural[$conflict['attribute']] = true;
                } elseif ($conflict['key'] !== null) {
                    $conflicts['segments'][$conflict['key']] = ['reason' => $conflict['reason'], 'theirs' => $conflict['theirs']];
                }
            }

            foreach ($changes as $key => $text) {
                $attribute = $previous['segments'][$key][0] ?? null;

                if (isset($structural[$attribute]) || ! array_key_exists($key, $texts)) {
                    // The text no longer fits the source: keep it to copy from.
                    if ($text !== null && is_string($attribute)) {
                        $conflicts['attributes'][$attribute][] = $text;
                    }

                    continue;
                }

                $texts[$key] = $text;
            }

            $root->fillPartially(
                [self::TRANSLATIONS => [$locale => $texts]],
                [self::TRANSLATIONS.'.'.$locale],
            );

            $this->setConflicts($root, $locale, $conflicts);
        }

        $root->partialRawState([self::LOADED => $loaded]);
    }

    private function statePath(Schema $root, string $locale, string $key): string
    {
        $prefix = $root->getStatePath();

        return (filled($prefix) ? $prefix.'.' : '').self::TRANSLATIONS.'.'.$locale.'.'.$key;
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

        $this->record->forgetTranslations($locale);

        if ($this->supportsStates()) {
            foreach ($this->attributes() as $attribute) {
                $this->record->forgetTranslationDraft($attribute, $locale)->setTranslationSource($attribute, $locale, null);
            }
        }

        $this->record->save();
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

        // Reviewed against the current source: every translated segment is
        // current, with the fingerprint of the source text it now matches.
        $this->record->lockTranslationsForUpdate(function () use ($locale): void {
            $this->flush();
            $content = $this->content();

            foreach ($this->attributes() as $attribute) {
                $texts = $content->alignment($locale, $attribute)->texts;
                $sources = [];

                foreach ($content->segments()[$attribute] ?? [] as $segment) {
                    if (isset($texts[$segment->path])) {
                        $sources[$segment->path] = $segment->hash();
                    }
                }

                $this->record->setTranslationSource($attribute, $locale, $sources === [] ? null : $sources);
            }
        });

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
        return $this->progress ??= TranslationProgress::of($this->content(), $this->offeredLocales()->except($this->sourceLocale()->code));
    }

    public function localeProgress(string $locale): LocaleProgress
    {
        return $this->progress()->locale($locale)
            ?? TranslationProgress::of($this->content(), $this->registry()->all()->only([$locale]))->locale($locale);
    }

    /**
     * The locale readers of the content context see instead of a missing
     * attribute, or null when they see nothing.
     */
    public function fallbackLocale(string $attribute, string $locale): ?Locale
    {
        if ($this->record->getTranslation($attribute, $locale) !== null) {
            return null;
        }

        $resolved = app(ContentContext::class)->run(
            $this->context,
            fn () => $this->record->resolveTranslation($attribute, $locale),
        );

        return $resolved->isFallback() ? $this->registry()->find((string) $resolved->locale) : null;
    }

    public function flush(): void
    {
        $this->progress = null;
        $this->localeSegments = [];
        $this->content?->flush();
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
