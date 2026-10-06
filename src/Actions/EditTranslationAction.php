<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Actions;

use Closure;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use JsonException;
use LogicException;
use Tipi\Translations\Actions\UpdateTranslation;
use Tipi\Translations\Contracts\LocaleProvider;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Translation;
use Tipi\Translations\TranslationManager;

class EditTranslationAction extends Action
{
    protected (Model&TranslatableModel)|Closure|null $translatableRecord = null;

    protected string|Closure|null $localeCode = null;

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

    public static function getDefaultName(): ?string
    {
        return 'edit_translation';
    }

    public function translatable((Model&TranslatableModel)|Closure $record): static
    {
        $this->translatableRecord = $record;

        return $this;
    }

    public function localeCode(string|Closure $localeCode): static
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

    public function getLocaleCode(): string
    {
        $localeCode = $this->evaluate($this->localeCode);

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

        $translation = $this->getTranslationManager()->get(
            translatable: $record,
            localeCode: $this->getLocaleCode(),
        );

        if ($translation === null) {
            throw new LogicException(
                'Translation must exist before it can be edited.',
            );
        }

        return $translation;
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

        $targetLocaleCode = $this->getLocaleCode();

        $sourceLocaleCodes = [
            $this->getLocaleProvider()->current()->code,
            $this->getLocaleProvider()->default()->code,
        ];

        foreach (array_unique($sourceLocaleCodes) as $localeCode) {
            if ($localeCode === $targetLocaleCode) {
                continue;
            }

            $translation = $this->getTranslationManager()->get(
                translatable: $record,
                localeCode: $localeCode,
            );

            if ($translation !== null) {
                return $translation;
            }
        }

        return null;
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
     * @throws JsonException
     */
    public function hasSourceTranslation(): bool
    {
        return $this->getSourceTranslation() !== null;
    }

    /**
     * @throws JsonException
     */
    public function getSourceTranslationSectionLabel(): ?string
    {
        $localeCode = $this->getSourceTranslation()?->localeCode;

        if ($localeCode === null) {
            return null;
        }

        return $this->getLocaleProvider()
            ->supportedLocale($localeCode)
            ->name;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Edit Translation')
            ->icon('heroicon-c-pencil-square')
            ->tableIcon('heroicon-c-pencil-square')
            ->color('primary')
           /* ->authorize(
                fn (): bool => ($record = $this->getTranslatableRecord()) !== null
                    && Gate::allows('translate', $record),
            )*/
            ->modalHeading(fn (): string => $this->getModalHeading())
            ->modalSubmitActionLabel('Save Translation')
            ->modalWidth(Width::SevenExtraLarge)
            ->fillForm(
                fn (): array => [
                    'translation' => $this->getTranslationFormData(),
                ],
            )
            ->schema(fn (): array => [
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

                    resolve(UpdateTranslation::class)->execute(
                        translatable: $translatable,
                        attributes: $data['translation'],
                        localeCode: $this->getLocaleCode(),
                    );

                    $this->getLivewire()->dispatch('translations-updated');

                    Notification::make()
                        ->title('Translation updated')
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
     * @return array<string, mixed>
     */
    protected function getTranslationFormData(): array
    {
        $translation = $this->getTranslation();

        return collect(array_keys($this->translationSchema))
            ->mapWithKeys(
                fn (string $attribute): array => [
                    $attribute => data_get(
                        $translation->attributes,
                        $attribute,
                    ),
                ],
            )
            ->all();
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
                function (
                    Closure $factory,
                    string $attribute,
                ) use ($sourceTranslation): Component {
                    $translationField = $factory()
                        ->hiddenLabel()
                        ->statePath("translation.$attribute")
                        ->columnSpan(1);

                    $sourceField = TextEntry::make(
                        "source_translation.$attribute",
                    )
                        ->hiddenLabel()
                        ->state(
                            data_get(
                                $sourceTranslation->attributes,
                                $attribute,
                            ),
                        )
                        ->columnSpan(1)
                        ->visible(fn (): bool => $this->hasSourceTranslation());

                    return Grid::make()
                        ->schema([
                            $sourceField,
                            $translationField,
                        ]);
                },
            )
            ->values()
            ->all();
    }

    protected function getLocaleProvider(): LocaleProvider
    {
        return resolve(LocaleProvider::class);
    }

    protected function getTranslationManager(): TranslationManager
    {
        return resolve(TranslationManager::class);
    }
}
