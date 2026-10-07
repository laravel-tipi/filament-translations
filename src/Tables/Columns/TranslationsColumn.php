<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Tables\Columns;

use Closure;
use Filament\Tables\Columns\Column;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Tipi\Support\Locale;
use Tipi\Translations\Contracts\LocaleProvider;
use Tipi\Translations\Contracts\TranslatableModel;

class TranslationsColumn extends Column
{
    protected string $view = 'tipi-filament-translations::tables.columns.translations-column';

    protected string $headerView = 'tipi-filament-translations::tables.columns.translations-column-header';

    protected (Model&TranslatableModel)|Closure|null $translatableRecord = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->disabledClick()
            ->label(
                fn () => new HtmlString(
                    view(
                        $this->getHeaderView(),
                        [
                            'column' => $this,
                        ],
                    )->render(),
                )
            );
    }

    public function getHeaderView(): string
    {
        return $this->headerView;
    }

    public function translatable((Model&TranslatableModel)|Closure $record): static
    {
        $this->translatableRecord = $record;

        return $this;
    }

    /**
     * @return Collection<string, Locale>
     */
    public function getLocales(): Collection
    {
        $defaultLocaleCode = $this->getLocaleProvider()
            ->default()
            ->code;

        return $this->getLocaleProvider()
            ->supported()
            ->reject(
                fn (Locale $locale): bool => $locale->code === $defaultLocaleCode,
            );
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

    public function translationExists(
        Model&TranslatableModel $record,
        string $localeCode,
    ): bool {
        return $record->translationExists(
            localeCode: $localeCode,
        );
    }

    private function getLocaleProvider(): LocaleProvider
    {
        return resolve(LocaleProvider::class);
    }
}
