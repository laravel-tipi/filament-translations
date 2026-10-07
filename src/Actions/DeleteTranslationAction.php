<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Actions;

use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Tipi\Translations\Actions\DeleteTranslation;
use Tipi\Translations\Contracts\LocaleProvider;
use Tipi\Translations\Contracts\TranslatableModel;

class DeleteTranslationAction extends Action
{
    protected (Model&TranslatableModel)|Closure|null $translatableRecord = null;

    protected string|Closure|null $localeCode = null;

    protected string|Closure|null $recordTitle = null;

    public static function getDefaultName(): ?string
    {
        return 'delete_translation';
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

    public function recordTitle(string|Closure|null $title): static
    {
        $this->recordTitle = $title;

        return $this;
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

    public function getTranslationLocaleName(): string
    {
        return $this->getLocaleProvider()
            ->supportedLocale($this->getLocaleCode())
            ->name;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Delete')
            ->icon('heroicon-o-trash')
            ->tableIcon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(
                fn (): string => sprintf(
                    'Delete %s Translation',
                    $this->getTranslationLocaleName(),
                ),
            )
            ->modalDescription(
                fn (): string => sprintf(
                    'Are you sure you want to delete the %s translation for %s?',
                    $this->getTranslationLocaleName(),
                    $this->getTranslatableRecordTitle(),
                ),
            )
            ->modalSubmitActionLabel('Delete Translation')
            ->action(function (): void {
                $translatable = $this->getTranslatableRecord();

                if ($translatable === null) {
                    throw new LogicException(
                        'Translatable record must not be null.',
                    );
                }

                resolve(DeleteTranslation::class)->execute(
                    translatable: $translatable,
                    localeCode: $this->getLocaleCode(),
                );

                $this->getLivewire()->dispatch('translations-updated');

                Notification::make()
                    ->title('Translation deleted')
                    ->success()
                    ->send();
            });
    }

    private function getLocaleProvider(): LocaleProvider
    {
        return resolve(LocaleProvider::class);
    }
}
