<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Components;

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Contracts\TranslatableContentDriver;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use JsonException;
use Livewire\Attributes\On;
use Livewire\Component;
use LogicException;
use Tipi\Translations\Contracts\LocaleProvider;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Filament\Actions\DeleteTranslationAction;
use Tipi\Translations\Filament\Actions\EditTranslationAction;
use Tipi\Translations\Filament\Actions\TranslateAction;
use Tipi\Translations\Filament\Contracts\HasTranslationSchema;
use Tipi\Translations\Translation;
use Tipi\Translations\TranslationManager;

class TranslationsManager extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public Model $record;

    /**
     * @var class-string<resource&HasTranslationSchema>
     */
    public string $resource;

    public function mount(
        Model $record,
        string $resource,
    ): void {
        if (! $record instanceof TranslatableModel) {
            throw new LogicException(
                'Translations manager requires a translatable model.',
            );
        }

        $this->record = $record;
        $this->resource = $resource;
    }

    #[On('translations-updated')]
    public function refreshTranslations(): void
    {
        $this->resetTable();
    }

    public function makeFilamentTranslatableContentDriver(): ?TranslatableContentDriver
    {
        return null;
    }

    public function getTranslatableRecord(): Model&TranslatableModel
    {
        $record = $this->record;

        if (! $record instanceof TranslatableModel) {
            throw new LogicException(
                'Translations manager requires a translatable model.',
            );
        }

        /** @var Model&TranslatableModel $record */
        return $record;
    }

    /**
     * @return Collection<string, array<string, mixed>>
     *
     * @throws JsonException
     */
    protected function getTranslationRecords(): Collection
    {
        $currentLocaleCode = $this->getLocaleProvider()
            ->current()
            ->code;

        return $this->getTranslationManager()
            ->getAll(
                translatable: $this->getTranslatableRecord(),
            )
            ->reject(
                fn (Translation $translation): bool => $translation->localeCode === $currentLocaleCode,
            )
            ->map(
                fn (Translation $translation): array => [
                    'key' => $translation->localeCode,
                    'locale_code' => $translation->localeCode,
                    'locale_name' => $this->getLocaleProvider()
                        ->supportedLocale($translation->localeCode)
                        ->name,
                    'attributes' => $translation->attributes,
                    'is_default' => $this->getLocaleProvider()
                        ->default()->code === $translation->localeCode,
                ],
            );
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => $this->getTranslationRecords())
            ->columns([
                TextColumn::make('locale_name')
                    ->label('Name'),
                TextColumn::make('is_default')
                    ->label('Status'),
            ])
            ->headerActions([
                TranslateAction::make()
                    ->translatable(
                        fn (): Model&TranslatableModel => $this->getTranslatableRecord(),
                    )
                    ->translationSchema(
                        (array) $this->getTranslationSchema(),
                    ),
            ])
            ->recordActions([
                EditTranslationAction::make()
                    ->translatable(
                        fn (): Model&TranslatableModel => $this->getTranslatableRecord(),
                    )
                    ->localeCode(
                        fn (array $record): string => $record['locale_code'],
                    )
                    ->translationSchema((array) $this->getTranslationSchema()),
                DeleteTranslationAction::make()
                    ->translatable(
                        fn (): Model&TranslatableModel => $this->getTranslatableRecord(),
                    )
                    ->localeCode(
                        fn (array $record): string => $record['locale_code'],
                    ),
            ]);
    }

    public function render(): View
    {
        return view(
            'tipi-filament-translations::components.translations-manager',
        );
    }

    /**
     * @return array<string, Closure>
     */
    protected function getTranslationSchema(): array
    {
        return $this->resource::getTranslationSchema();
    }

    private function getLocaleProvider(): LocaleProvider
    {
        return resolve(LocaleProvider::class);
    }

    private function getTranslationManager(): TranslationManager
    {
        return resolve(TranslationManager::class);
    }
}
