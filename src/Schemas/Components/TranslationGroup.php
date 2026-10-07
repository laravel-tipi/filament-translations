<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Schemas\Components;

use Closure;
use Filament\Schemas\Components\Group;
use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Contracts\LocaleProvider;
use Tipi\Translations\Contracts\TranslatableModel;

class TranslationGroup extends Group
{
    protected ?string $localeCode = null;

    public static function make(array|Closure $schema = []): static
    {
        return parent::make($schema)
            ->statePath('translation')
            ->afterStateHydrated(
                function (TranslationGroup $component, ?Model $record): void {
                    if (! $record instanceof TranslatableModel) {
                        return;
                    }

                    $translation = $record->getTranslation(
                        $component->getLocaleCode(),
                    );

                    $component->state(
                        $translation?->attributes ?? [],
                    );
                },
            );
    }

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
}
