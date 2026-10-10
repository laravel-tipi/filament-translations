<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Actions;

use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Actions\MarkOthersAsOutdated;
use Tipi\Translations\Contracts\HasTranslationStates;
use Tipi\Translations\Contracts\LocaleProvider;

class MarkOthersAsOutdatedAction extends Action
{
    protected (Model&HasTranslationStates)|Closure|null $translatableRecord = null;

    protected string|Closure|null $localeCode = null;

    public static function getDefaultName(): ?string
    {
        return 'mark_others_as_outdated';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Mark other translations as outdated')
            ->icon('heroicon-o-arrow-path')
            ->visible(
                fn (): bool => $this->getTranslatableRecord() !== null,
            )
            ->action(function (): void {
                $record = $this->getTranslatableRecord();

                if ($record === null) {
                    return;
                }

                resolve(MarkOthersAsOutdated::class)->execute(
                    translatable: $record,
                    localeCode: $this->getLocaleCode(),
                );

                Notification::make()
                    ->title('Other translations marked as outdated')
                    ->success()
                    ->send();
            });
    }

    public function translatable(
        (Model&HasTranslationStates)|Closure $record,
    ): static {
        $this->translatableRecord = $record;

        return $this;
    }

    public function localeCode(string|Closure|null $localeCode): static
    {
        $this->localeCode = $localeCode;

        return $this;
    }

    public function getLocaleCode(): string
    {
        return $this->evaluate($this->localeCode)
            ?? $this->getLocaleProvider()->current()->code;
    }

    public function getTranslatableRecord(): (Model&HasTranslationStates)|null
    {
        $record = $this->translatableRecord !== null
            ? $this->evaluate($this->translatableRecord)
            : $this->getRecord();

        if (
            ! $record instanceof Model
            || ! $record instanceof HasTranslationStates
        ) {
            return null;
        }

        return $record;
    }

    private function getLocaleProvider(): LocaleProvider
    {
        return resolve(LocaleProvider::class);
    }
}
