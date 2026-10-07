<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Schemas\Components\Concerns;

use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Contracts\LocaleProvider;
use Tipi\Translations\Contracts\TranslatableModel;

trait HasTranslationState
{
    protected ?string $localeCode = null;

    public function locale(?string $localeCode): static
    {
        $this->localeCode = $localeCode;

        return $this;
    }

    public function getLocaleCode(): string
    {
        return $this->localeCode
            ?? resolve(LocaleProvider::class)->current()->code;
    }

    protected function setUpTranslation(): void
    {
        $this
            ->statePath("translation.{$this->getName()}")
            ->afterStateHydrated(
                function (Component $component, ?Model $record): void {
                    if (! $record instanceof TranslatableModel) {
                        return;
                    }

                    $translation = $record->getTranslation(
                        $this->getLocaleCode(),
                    );

                    $component->state(
                        data_get(
                            $translation?->attributes,
                            $this->getName(),
                        ),
                    );
                },
            );
    }
}
