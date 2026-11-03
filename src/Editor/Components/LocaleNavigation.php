<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Editor\Components;

use Filament\Forms\Components\Field;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Tipi\Support\Locale;
use Tipi\Translations\Filament\Editor\RecordEditor;
use Tipi\Translations\Filament\Enums\LocaleNavigation as Navigation;
use Tipi\Translations\Filament\Support\LocaleProgress;

/**
 * Switches the target locale, as tabs or a select, showing each locale's
 * progress, whether it is outdated or inactive, and whether it holds
 * validation errors. Both variants set the same state.
 *
 * @internal
 */
final class LocaleNavigation extends Field
{
    protected string $view = 'tipi-filament-translations::components.locale-navigation';

    private RecordEditor $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->hiddenLabel()
            ->live()
            ->dehydrated(false)
            ->validatedWhenNotDehydrated(false)
            ->afterStateUpdated(fn (LocaleNavigation $component, ?string $state, ?string $old) => $this->editor->switchLocale($component, $state, $old));
    }

    public function editor(RecordEditor $editor): self
    {
        $this->editor = $editor;

        return $this;
    }

    public function getVariant(): Navigation
    {
        return $this->editor->config->getNavigation($this->getTargets()->count());
    }

    /**
     * @return list<array{locale: Locale, progress: LocaleProgress, active: bool, errors: bool}>
     */
    public function getItems(): array
    {
        $active = $this->getState();
        $errors = $this->getErrorLocales();

        return $this->getTargets()
            ->map(fn (Locale $locale): array => [
                'locale' => $locale,
                'progress' => $this->editor->localeProgress($locale->code),
                'active' => $locale->code === $active,
                'errors' => in_array($locale->code, $errors, true),
            ])
            ->values()
            ->all();
    }

    /**
     * @return Collection<string, Locale>
     */
    private function getTargets(): Collection
    {
        return $this->editor->targets($this->getRootContainer()->getRawState()[RecordEditor::SOURCE_LOCALE] ?? null);
    }

    /**
     * Locales with fields that failed validation.
     *
     * @return list<string>
     */
    private function getErrorLocales(): array
    {
        $root = $this->getRootContainer()->getStatePath();
        $prefix = (filled($root) ? $root.'.' : '').RecordEditor::TRANSLATIONS.'.';
        $locales = [];

        foreach ($this->getLivewire()->getErrorBag()->keys() as $key) {
            if (str_starts_with($key, $prefix)) {
                $locales[] = Str::before(Str::after($key, $prefix), '.');
            }
        }

        return array_values(array_unique($locales));
    }
}
