<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Schemas\Components\Concerns;

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Contracts\LocaleProvider;
use Tipi\Translations\Contracts\TranslatableModel;

trait HasTranslation
{
    use HasTranslationValidation;
    protected ?string $translationLocaleCode = null;

    protected bool $shouldHydrateTranslation = true;

    public function locale(?string $localeCode): static
    {
        $this->translationLocaleCode = $localeCode;

        return $this;
    }

    public function hydrateTranslation(bool $condition = true): static
    {
        $this->shouldHydrateTranslation = $condition;

        return $this;
    }

    public function getLocaleCode(): string
    {
        return $this->translationLocaleCode
            ?? resolve(LocaleProvider::class)->current()->code;
    }

    protected function setUpTranslation(): void
    {
        $name = $this->getName();

        $this
            ->statePath("translation.$name")
            ->afterStateHydrated(
                function () use ($name): void {
                    if (! $this->shouldHydrateTranslation) {
                        return;
                    }

                    $record = $this->getRecord();

                    if (
                        ! $record instanceof Model
                        || ! $record instanceof TranslatableModel
                    ) {
                        return;
                    }

                    $translation = $record->getTranslation(
                        $this->getLocaleCode(),
                    );

                    $this->state(
                        data_get(
                            $translation?->attributes,
                            $name,
                        ),
                    );
                },
            );
    }
}
