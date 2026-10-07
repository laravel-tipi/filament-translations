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
use Tipi\Translations\Actions\UpdateTranslation;
use Tipi\Translations\Contracts\LocaleProvider;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Filament\Schemas\Components\Contracts\TranslationField;
use Tipi\Translations\Filament\Schemas\Components\TranslationContainer;
use Tipi\Translations\Translation;

class EditTranslationAction extends Action
{
    protected (Model&TranslatableModel)|Closure|null $translatableRecord = null;

    protected string|Closure|null $targetLocaleCode = null;

    protected string|Closure|null $sourceLocaleCode = null;

    /**
     * @var array<string, Closure>
     */
    protected array $translationSchema = [];

    protected string|Closure|null $recordTitle = null;

    public static function getDefaultName(): ?string
    {
        return 'edit_translation';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Edit')
            ->icon('heroicon-c-pencil-square')
            ->tableIcon('heroicon-c-pencil-square')
            ->color('primary')
            ->modalSubmitAction(false)
            ->modalCancelAction(false)
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

    public function localeCode(string|Closure $targetLocaleCode): static
    {
        $this->targetLocaleCode = $targetLocaleCode;

        return $this;
    }

    public function getLocaleCode(): string
    {
        $localeCode = $this->evaluate($this->targetLocaleCode);

        if ($localeCode === null) {
            throw new LogicException(
                'Translation locale code must not be null.',
            );
        }

        return $localeCode;
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

    /**
     * @throws JsonException
     */
    public function getTranslation(): Translation
    {
        $record = $this->getTranslatableRecord();

        if ($record === null) {
            throw new LogicException(
                'Translatable record must not be null.',
            );
        }

        $translation = $record->getTranslation(
            $this->getLocaleCode(),
        );

        if ($translation === null) {
            throw new LogicException(
                'Translation must exist before it can be edited.',
            );
        }

        return $translation;
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

    protected function getSourceLocales(): Collection
    {
        $record = $this->getTranslatableRecord();

        if ($record === null) {
            return collect();
        }

        $localeCode = $this->getLocaleCode();

        $translatedLocaleCodes = $record
            ->getTranslations()
            ->pluck('localeCode');

        return $this->getLocaleProvider()
            ->supported()
            ->filter(
                fn (Locale $locale): bool => $translatedLocaleCodes->contains($locale->code)
                    && $locale->code !== $localeCode,
            );
    }

    public function hasSourceTranslation(): bool
    {
        return $this->getSourceLocaleCode() !== null;
    }

    protected function getSourceLocaleSelect(): Select
    {
        return Select::make('source_locale_code')
            ->label('Source Language')
            ->live()
            ->options(
                fn (): array => $this->getLocaleOptions(
                    $this->getSourceLocales(),
                ),
            )
            ->allowHtml()
            ->searchable()
            ->selectablePlaceholder(false)
            ->afterStateUpdated(
                function (Set $set, ?string $state): void {
                    $set(
                        'source_translation',
                        $this->getTranslationState($state),
                    );
                },
            );
    }

    protected function getTargetLocaleSelect(): Select
    {
        $locale = $this->getLocaleProvider()
            ->supportedLocale($this->getLocaleCode());

        return Select::make('target_locale_code')
            ->label('Target Language')
            ->options(
                $this->getLocaleOptions(
                    collect([$locale->code => $locale]),
                ),
            )
            ->disabled()
            ->allowHtml()
            ->selectablePlaceholder(false);
    }

    protected function getTranslationContainer(): TranslationContainer
    {
        $container = TranslationContainer::make();

        $container
            ->sourceVisible(fn (): bool => $this->hasSourceTranslation())
            ->sourceLocale([
                $this->getSourceLocaleSelect(),
            ])
            ->targetLocale([$this->getTargetLocaleSelect()])
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

        $container
            ->sourceFooter([
                Action::make('saveSourceTranslation')
                    ->label('Save Source')
                    ->action(function (Get $get) use ($container): void {
                        $container->validateSource();

                        $localeCode = $get('source_locale_code');

                        if ($localeCode === null) {
                            return;
                        }

                        $this->updateTranslation(
                            localeCode: $localeCode,
                            attributes: $get('source_translation'),
                        );
                    }),
            ])
            ->targetFooter([
                Action::make('saveTargetTranslation')
                    ->label('Save Translation')
                    ->action(function (Get $get) use ($container): void {
                        $container->validateTarget();

                        $this->updateTranslation(
                            localeCode: $this->getLocaleCode(),
                            attributes: $get('target_translation'),
                        );
                    }),
            ]);

        return $container;
    }

    protected function getInitialFormState(): array
    {
        $sourceLocaleCode = $this->getDefaultSourceLocaleCode();
        $targetLocaleCode = $this->getLocaleCode();

        return [
            'source_locale_code' => $sourceLocaleCode,
            'source_translation' => $this->getTranslationState(
                $sourceLocaleCode,
            ),
            'target_locale_code' => $targetLocaleCode,
            'target_translation' => $this->getTranslationState(
                $this->getLocaleCode(),
            ),
        ];
    }

    protected function getTranslationState(?string $localeCode): array
    {
        if ($localeCode === null) {
            return [];
        }

        return $this->getTranslatableRecord()
            ?->getTranslation($localeCode)
            ?->attributes ?? [];
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

    public function getModalHeading(): string
    {
        $locale = $this->getLocaleProvider()
            ->supportedLocale($this->getLocaleCode());

        return "Edit {$this->getTranslatableRecordTitle()} $locale->name Translation";
    }

    public function getTranslationSectionLabel(): string
    {
        return $this->getLocaleProvider()
            ->supportedLocale($this->getLocaleCode())
            ->name;
    }

    /**
     * @param  array<string, Closure>  $schema
     */
    public function translationSchema(array $schema): static
    {
        $this->translationSchema = $schema;

        return $this;
    }

    /**
     * @throws Throwable
     */
    protected function updateTranslation(
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
            ->title("$locale->name translation updated")
            ->success()
            ->send();
    }

    private function getLocaleProvider(): LocaleProvider
    {
        return resolve(LocaleProvider::class);
    }
}
