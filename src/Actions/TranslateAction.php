<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Actions;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use JsonException;
use LogicException;
use Throwable;
use Tipi\Support\Locale;
use Tipi\Support\Validation\Validator;
use Tipi\Translations\Actions\CreateTranslation;
use Tipi\Translations\Actions\UpdateTranslation;
use Tipi\Translations\Contracts\LocaleProvider;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Exceptions\TranslationAlreadyExistsException;
use Tipi\Translations\Filament\Schemas\Components\Contracts\TranslationField;
use Tipi\Translations\Filament\Schemas\Components\TranslationContainer;
use Tipi\Translations\Translation;

class TranslateAction extends Action
{
    protected (Model&TranslatableModel)|Closure|null $translatableRecord = null;

    protected string|Closure|null $targetLocaleCode = null;

    protected string|Closure|null $selectedTargetLocaleCode = null;

    protected string|Closure|null $sourceLocaleCode = null;

    /**
     * @var array<string, Closure>
     */
    protected array $translationSchema = [];

    protected string|Closure|null $recordTitle = null;

    public static function getDefaultName(): ?string
    {
        return 'translate';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(fn (): string => $this->getTranslationButtonLabel())
            ->color('primary')
            ->icon(fn () => $this->getTranslationButtonIcon())
            ->tableIcon(fn () => $this->getTranslationButtonIcon())
            ->groupedIcon(fn () => $this->getTranslationButtonIcon())
            ->modalSubmitAction(false)
            ->modalCancelAction(false)
           /* ->authorize(
                fn (): bool => Gate::allows(
                    'translate',
                    $this->getTranslatableRecord(),
                ),
            )*/
            ->visible(fn (): bool => $this->shouldBeVisible())
            ->modalHeading(fn (): string => $this->getModalHeading())
            ->modalWidth(Width::SevenExtraLarge)
            ->fillForm(fn (): array => $this->getInitialFormState())
            ->schema(fn (): array => [
                $this->getTranslationContainer(),
            ]);
    }

    public function recordTitle(string|Closure|null $title): static
    {
        $this->recordTitle = $title;

        return $this;
    }

    public function translatable((Model&TranslatableModel)|Closure $record): static
    {
        $this->translatableRecord = $record;

        return $this;
    }

    /** @deprecated use targetLocaleCode() */
    public function localeCode(string|Closure|null $localeCode): static
    {
        $this->targetLocaleCode = $localeCode;

        return $this;
    }

    public function targetLocaleCode(string|Closure|null $localeCode): static
    {
        $this->targetLocaleCode = $localeCode;

        return $this;
    }

    public function getTranslatableRecordTitle(): string
    {
        if ($title = $this->evaluate($this->recordTitle)) {
            return $title;
        }

        $record = $this->getTranslatableRecord();

        if ($record === null) {
            throw new LogicException(
                'Translatable record must not be null.',
            );
        }

        return (string) $record->getKey();
    }

    public function getTranslatableRecord(): (Model&TranslatableModel)|null
    {
        $record = $this->translatableRecord !== null
            ? $this->evaluate($this->translatableRecord)
            : $this->getRecord();

        if (
            ! $record instanceof Model
            || ! $record instanceof TranslatableModel
        ) {
            return null;
        }

        /** @var Model&TranslatableModel $record */
        return $record;
    }

    public function getTargetLocaleCode(): ?string
    {
        return $this->evaluate($this->targetLocaleCode)
            ?? $this->getSelectedTargetLocaleCode();
    }

    public function getSourceLocaleCode(): ?string
    {
        if ($this->sourceLocaleCode !== null) {
            return $this->evaluate($this->sourceLocaleCode);
        }

        return $this->getDefaultSourceLocaleCode();
    }

    protected function getDefaultSourceLocaleCode(): ?string
    {
        $locales = $this->getSourceLocales();

        $currentLocaleCode = $this->getLocaleProvider()->current()->code;

        if ($locales->has($currentLocaleCode)) {
            return $currentLocaleCode;
        }

        $defaultLocaleCode = $this->getLocaleProvider()->default()->code;

        if ($locales->has($defaultLocaleCode)) {
            return $defaultLocaleCode;
        }

        /** @var Collection<string, Locale> $locales */
        return $locales->first()?->code;
    }

    public function getSelectedTargetLocaleCode(): ?string
    {
        if ($this->selectedTargetLocaleCode !== null) {
            return $this->evaluate($this->selectedTargetLocaleCode);
        }

        return $this->getSingleMissingLocaleCode();
    }

    /**
     * @throws JsonException
     */
    public function hasSourceTranslation(): bool
    {
        return $this->getSourceTranslation() !== null;
    }

    /**
     * @throws JsonException
     */
    public function getSourceTranslation(): ?Translation
    {
        $record = $this->getTranslatableRecord();
        $localeCode = $this->getSourceLocaleCode();

        if ($record === null) {
            throw new LogicException(
                'Translatable record must not be null.',
            );
        }

        if ($localeCode === null) {
            return null;
        }

        return $record->getTranslation($localeCode);
    }

    protected function hasSingleMissingLocale(): bool
    {
        return $this->getMissingLocales()->count() === 1;
    }

    protected function getSingleMissingLocaleCode(): ?string
    {
        if (! $this->hasSingleMissingLocale()) {
            return null;
        }

        /** @var Locale $locale */
        $locale = $this->getMissingLocales()->first();

        return $locale?->code;
    }

    protected function getSourceLocaleSelect(): Select
    {
        return Select::make('source_locale_code')
            ->label('Source Language')
            ->live()
            ->options(
                fn (): array => $this->getLocaleOptions($this->getSourceLocales()),
            )
            ->allowHtml()
            ->searchable()
            ->selectablePlaceholder(false)
            ->afterStateUpdated(
                function (Set $set, ?string $state): void {
                    $set(
                        'source_translation',
                        $this->getSourceTranslationState($state),
                    );
                },
            );
    }

    protected function getTargetLocaleSelect(): Select
    {
        return Select::make('target_locale_code')
            ->label('Target Language')
            ->required()
            ->live()
            ->options(
                fn (): array => $this->getLocaleOptions($this->getMissingLocales()),
            )
            ->disabled(
                fn (): bool => $this->hasConfiguredTargetLocale()
                    || $this->hasSingleMissingLocale(),
            )
            ->allowHtml()
            ->searchable()
            ->afterStateUpdated(function (?string $state): void {
                $this->selectedTargetLocaleCode = $state;
            })
            ->selectablePlaceholder(false);
    }

    public function getModalHeading(): string
    {
        $localeCode = $this->getTargetLocaleCode();

        $title = $this->getTranslatableRecordTitle();

        if ($localeCode === null) {
            return "Translate $title";
        }

        $locale = $this->getLocaleProvider()->supportedLocale($localeCode);

        return "Translate $title to $locale->name";
    }

    public function getModalSubmitActionLabel(): string
    {
        return 'Create Translation';
    }

    public function getTranslationButtonLabel(): string
    {
        if ($this->getTargetLocaleCode() !== null) {
            $locale = $this->getLocaleProvider()->supportedLocale($this->getTargetLocaleCode());

            return "Translate to $locale->name";
        }

        return 'Translate';
    }

    /**
     * @throws JsonException
     */
    public function shouldBeVisible(): bool
    {
        $record = $this->getTranslatableRecord();

        if ($record === null) {
            return false;
        }

        $localeCode = $this->getTargetLocaleCode();

        if ($localeCode !== null) {
            return ! $record->translationExists($localeCode);
        }

        return $this->getMissingLocales()->isNotEmpty();
    }

    /**
     * @throws JsonException
     */
    public function getSourceTranslationSectionLabel(): ?string
    {
        $locale = $this->getSourceTranslation()?->localeCode;

        if ($locale === null) {
            return null;
        }

        return $this->getLocaleProvider()->supportedLocale($locale)->name;
    }

    public function getTranslationSectionLabel(): string
    {
        $localeCode = $this->getTargetLocaleCode();

        if ($localeCode === null) {
            return 'Translation';
        }

        return $this->getLocaleProvider()
            ->supportedLocale($localeCode)
            ->name;
    }

    public function getTranslationButtonIcon(): string
    {
        return 'heroicon-o-language';
    }

    /**
     * @param  array<string, Closure>  $schema
     */
    public function translationSchema(array $schema): static
    {
        $this->translationSchema = $schema;

        return $this;
    }

    private function getLocaleProvider(): LocaleProvider
    {
        return resolve(LocaleProvider::class);
    }

    protected function getMissingLocales(): Collection
    {
        $record = $this->getTranslatableRecord();

        if ($record === null) {
            return collect();
        }

        return $this->getLocaleProvider()
            ->supported()
            ->reject(
                fn ($locale): bool => $record->translationExists(
                    $locale->code,
                ),
            );
    }

    protected function getSourceLocales(): Collection
    {
        $record = $this->getTranslatableRecord();

        if ($record === null) {
            return collect();
        }

        $targetLocaleCode = $this->getTargetLocaleCode();

        $translatedLocaleCodes = $record
            ->getTranslations()
            ->pluck('localeCode');

        return $this->getLocaleProvider()
            ->supported()
            ->filter(
                fn (Locale $locale): bool => $translatedLocaleCodes->contains($locale->code)
                    && $locale->code !== $targetLocaleCode,
            );
    }

    /**
     * @throws JsonException
     */
    protected function getTranslationContainer(): TranslationContainer
    {
        $container = TranslationContainer::make();

        $container
            ->sourceVisible(fn (): bool => $this->hasSourceTranslation())
            ->sourceLocale([
                $this->getSourceLocaleSelect(),
            ])
            ->targetLocale([
                $this->getTargetLocaleSelect(),

            ])
            ->sourceHeader([
                TextEntry::make('sourceHeading')
                    ->hiddenLabel()
                    ->state(function (Get $get): ?string {
                        $localeCode = $get('source_locale_code');

                        if ($localeCode === null) {
                            return null;
                        }

                        return $this->getLocaleProvider()
                            ->supportedLocale($localeCode)
                            ->name;
                    })
                    ->size(TextSize::Medium),
            ])
            ->sourceFooter([
                Action::make('saveSourceTranslation')
                    ->label('Save Source')
                    ->action(function (Get $get) use ($container): void {
                        $attributes = $container->getSourceState();

                        $localeCode = $get('source_locale_code');

                        if ($localeCode === null) {
                            return;
                        }

                        $this->updateSourceTranslation(
                            localeCode: $localeCode,
                            attributes: $attributes,
                        );
                    })
                    ->extraAttributes([
                        'data-translation-action' => 'source',
                    ]),
            ])
            ->targetFooter([
                Action::make('createTargetTranslation')
                    ->label('Create Translation')
                    ->action(function (Get $get) use ($container): void {
                        $attributes = $container->getTargetState();

                        $localeCode = $this->getTargetLocaleCode()
                            ?? $get('target_locale_code');

                        if ($localeCode === null) {
                            return;
                        }

                        $this->createTargetTranslation(
                            localeCode: $localeCode,
                            attributes: $attributes,
                        );
                    })
                    ->extraAttributes([
                        'data-translation-action' => 'target',
                    ]),
            ])
            ->targetHeader([
                TextEntry::make('translationHeading')
                    ->hiddenLabel()
                    ->state(fn (): string => $this->getTranslationSectionLabel())
                    ->size(TextSize::Medium),
            ]);

        foreach ($this->translationSchema as $attribute => $factory) {
            $sourceField = $factory();
            $targetField = $factory();

            if (
                ! $sourceField instanceof TranslationField
                || ! $targetField instanceof TranslationField
            ) {
                throw new LogicException(sprintf(
                    'Translation fields must implement [%s].',
                    TranslationField::class,
                ));
            }

            $sourceField
                ->hydrateTranslation(false)
                ->hiddenLabel()
                ->statePath("source_translation.$attribute");

            $targetField
                ->hydrateTranslation(false)
                ->hiddenLabel()
                ->statePath("target_translation.$attribute");

            $container->content(
                name: $attribute,
                source: [$sourceField],
                target: [$targetField],
            );
        }

        return $container;
    }

    protected function getSourceTranslationState(?string $localeCode): array
    {
        if ($localeCode === null) {
            return [];
        }

        return $this->getTranslatableRecord()
            ?->getTranslation($localeCode)
            ?->attributes ?? [];
    }

    protected function getInitialFormState(): array
    {
        $sourceLocaleCode = $this->getDefaultSourceLocaleCode();

        return [
            'source_locale_code' => $sourceLocaleCode,
            'source_translation' => $this->getSourceTranslationState(
                $sourceLocaleCode,
            ),
            'target_locale_code' => $this->getTargetLocaleCode()
                ?? $this->getSingleMissingLocaleCode(),
        ];
    }

    protected function getLocaleOptions(Collection $locales): array
    {
        return $locales
            ->mapWithKeys(
                fn (Locale $locale): array => [
                    $locale->code => Blade::render(
                        '<x-dynamic-component :component="$component" class="w-5 h-5 inline-block" /> {{ $name }}',
                        [
                            'component' => 'flag-4x3-'.strtolower(
                                $locale->countryCode ?? 'un',
                            ),
                            'name' => $locale->name,
                        ],
                    ),
                ],
            )
            ->all();
    }

    protected function hasConfiguredTargetLocale(): bool
    {
        return $this->targetLocaleCode !== null;
    }

    /**
     * @throws Throwable
     */
    protected function createTargetTranslation(
        string $localeCode,
        array $attributes,
    ): void {
        $record = $this->getTranslatableRecord();

        if ($record === null) {
            throw new LogicException(
                'Translatable record must not be null.',
            );
        }

        try {
            resolve(CreateTranslation::class)->execute(
                translatable: $record,
                attributes: $attributes,
                localeCode: $localeCode,
            );
        } catch (TranslationAlreadyExistsException $exception) {
            Validator::fail(
                field: 'target_locale_code',
                message: $exception->getMessage(),
                path: 'mountedActions.0.data',
            );
        }

        $locale = $this->getLocaleProvider()
            ->supportedLocale($localeCode);

        $this->getLivewire()->dispatch('translations-updated');

        Notification::make()
            ->title("Translated to $locale->name")
            ->success()
            ->send();
    }

    /**
     * @throws Throwable
     */
    protected function updateSourceTranslation(
        string $localeCode,
        array $attributes,
    ): void {
        $record = $this->getTranslatableRecord();

        if ($record === null) {
            throw new LogicException(
                'Translatable record must not be null.',
            );
        }

        resolve(UpdateTranslation::class)->execute(
            translatable: $record,
            attributes: $attributes,
            localeCode: $localeCode,
        );

        $locale = $this->getLocaleProvider()
            ->supportedLocale($localeCode);

        $this->getLivewire()->dispatch('translations-updated');

        Notification::make()
            ->title("Translated to $locale->name")
            ->success()
            ->send();
    }
}
