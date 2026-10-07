<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Actions;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use JsonException;
use LogicException;
use Tipi\Support\Locale;
use Tipi\Support\Validation\Validator;
use Tipi\Translations\Actions\CreateTranslation;
use Tipi\Translations\Contracts\LocaleProvider;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Exceptions\TranslationAlreadyExistsException;
use Tipi\Translations\Translation;

class TranslateAction extends Action
{
    protected (Model&TranslatableModel)|Closure|null $translatableRecord = null;

    protected string|Closure|null $localeCode = null;

    protected string|Closure|null $selectedLocaleCode = null;

    /**
     * @var array<string, Closure>
     */
    protected array $translationSchema = [];

    protected string|Closure|null $recordTitle = null;

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

    public function localeCode(string|Closure|null $localeCode): static
    {
        $this->localeCode = $localeCode;

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

    public function getLocaleCode(): ?string
    {
        return $this->evaluate($this->localeCode) ?? null;
    }

    public function getSelectedLocaleCode(): ?string
    {
        if ($this->selectedLocaleCode !== null) {
            return $this->evaluate($this->selectedLocaleCode);
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

        if ($record === null) {
            throw new LogicException(
                'Translatable record must not be null.',
            );
        }

        return $record->getTranslation(
            $this->getLocaleProvider()->current()->code,
        ) ?? $record->getTranslation(
            $this->getLocaleProvider()->default()->code,
        );
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

    protected function getLocaleSchema(): array
    {
        if ($this->getLocaleCode() !== null) {
            return [];
        }

        return [
            Grid::make(4)
                ->schema([
                    Select::make('locale_code')
                        ->label('Language')
                        ->required()
                        ->live()
                        ->options(
                            fn (): array => $this->getMissingLocales()
                                ->mapWithKeys(
                                    fn ($locale): array => [
                                        $locale->code => Blade::render(
                                            '<x-dynamic-component :component="$component" class="w-5 h-5 inline-block" /> {{ $name }}',
                                            [
                                                'component' => 'flag-4x3-'.strtolower($locale->countryCode),
                                                'name' => $locale->name,
                                            ],
                                        ),
                                    ],
                                )
                                ->all(),
                        )
                        ->default(fn (): ?string => $this->getSingleMissingLocaleCode())
                        ->disabled(fn (): bool => $this->hasSingleMissingLocale())
                        ->dehydrated()
                        ->allowHtml()
                        ->searchable(['code', 'name', 'native_name'])
                        ->afterStateUpdated(function (?string $state): void {
                            $this->selectedLocaleCode = $state;
                        })
                        ->columnStart(fn (): int => $this->hasSourceTranslation() ? 4 : 1)
                        ->extraAttributes([
                            'class' => 'max-w-60',
                        ]),
                ]),
        ];
    }

    public function getModalHeading(): string
    {
        $localeCode = $this->getLocaleCode()
            ?? $this->getSelectedLocaleCode();

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
        if ($this->getLocaleCode() !== null) {
            $locale = $this->getLocaleProvider()->supportedLocale($this->getLocaleCode());

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

        $localeCode = $this->getLocaleCode();

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
        $localeCode = $this->getLocaleCode()
            ?? $this->getSelectedLocaleCode();

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
           /* ->authorize(
                fn (): bool => Gate::allows(
                    'translate',
                    $this->getTranslatableRecord(),
                ),
            )*/
            ->visible(fn (): bool => $this->shouldBeVisible())
            ->modalHeading(fn (): string => $this->getModalHeading())
            ->modalSubmitActionLabel(fn (): string => $this->getModalSubmitActionLabel())
            ->modalWidth(Width::SevenExtraLarge)
            ->schema(fn (): array => [
                ...$this->getLocaleSchema(),
                Group::make()
                    ->schema([
                        Grid::make()
                            ->schema([
                                TextEntry::make('sourceHeading')
                                    ->hiddenLabel()
                                    ->state(fn (): ?string => $this->getSourceTranslationSectionLabel())
                                    ->size(TextSize::Medium)
                                    ->columnSpan(1)
                                    ->visible(fn (): bool => $this->hasSourceTranslation()),
                                TextEntry::make('translationHeading')
                                    ->hiddenLabel()
                                    ->state(fn (): string => $this->getTranslationSectionLabel())
                                    ->size(TextSize::Medium)
                                    ->columnSpan(1),
                            ]),
                        ...$this->getTranslationSchema(),
                    ]),

            ])
            ->action(
                function (array $data): void {
                    $translatable = $this->getTranslatableRecord();

                    if ($translatable === null) {
                        throw new LogicException(
                            'Translatable record must not be null.',
                        );
                    }

                    $localeCode = $this->getLocaleCode()
                        ?? (string) $data['locale_code'];

                    try {
                        resolve(CreateTranslation::class)->execute(
                            translatable: $translatable,
                            attributes: $data['translation'],
                            localeCode: $localeCode,
                        );
                    } catch (TranslationAlreadyExistsException $exception) {
                        Validator::fail(
                            field: 'locale_code',
                            message: $exception->getMessage(),
                            path: 'mountedActions.0.data',
                        );
                    }

                    $locale = $this->getLocaleProvider()->supportedLocale($localeCode);

                    $this->getLivewire()->dispatch('translations-updated');

                    Notification::make()
                        ->title("Translated to $locale->name")
                        ->success()
                        ->send();
                });
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
     * @return array<Component>
     *
     * @throws JsonException
     */
    protected function getTranslationSchema(): array
    {
        $sourceTranslation = $this->getSourceTranslation();

        return collect($this->translationSchema)
            ->map(
                function (Closure $factory, string $attribute) use ($sourceTranslation): Component {
                    $translationField = $factory()
                        ->hiddenLabel()
                        ->statePath("translation.$attribute")
                        ->columnSpan(1);

                    $sourceTranslation = TextEntry::make("source_translation.$attribute")
                        ->hiddenLabel()
                        ->state(
                            data_get(
                                $sourceTranslation?->attributes,
                                $attribute,
                            ),
                        )
                        ->columnSpan(1)
                        ->visible(fn (): bool => $this->hasSourceTranslation());

                    return Grid::make()
                        ->schema([
                            $sourceTranslation,
                            $translationField,
                        ]);
                },
            )
            ->values()
            ->all();
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
}
