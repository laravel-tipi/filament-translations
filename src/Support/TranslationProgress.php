<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Tipi\Support\Locale;
use Tipi\Translations\Concerns\HasTranslationStates;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Models\TranslationState;

/**
 * The translation progress of one record across locales. Reads only what
 * the model has loaded: eager load translations (and translation states)
 * to compute it for many records without further queries.
 */
final readonly class TranslationProgress
{
    /**
     * @param  Collection<string, LocaleProgress>  $locales
     */
    private function __construct(
        private Collection $locales,
    ) {}

    /**
     * @param  list<string>  $attributes  the attributes that count
     * @param  Collection<string, Locale>  $locales
     */
    public static function of(Model&TranslatableModel $record, array $attributes, Collection $locales): self
    {
        $stored = $record->getAllTranslations();
        $counted = array_flip($attributes);

        $states = self::supportsStates($record)
            ? $record->getRelationValue('translationStates')->keyBy('locale_code')
            : collect();

        return new self($locales->map(function (Locale $locale) use ($stored, $counted, $states): LocaleProgress {
            /** @var TranslationState|null $state */
            $state = $states->get($locale->code);

            return new LocaleProgress(
                locale: $locale,
                translated: count(array_intersect_key($stored[$locale->code] ?? [], $counted)),
                total: count($counted),
                outdatedSince: $state?->outdated_at,
                workflowStatus: $state?->status,
            );
        }));
    }

    /**
     * Whether the model records translation states (outdated and status).
     */
    public static function supportsStates(Model $record): bool
    {
        return in_array(HasTranslationStates::class, class_uses_recursive($record), true);
    }

    public function locale(string $code): ?LocaleProgress
    {
        return $this->locales->get($code);
    }

    /**
     * @return Collection<string, LocaleProgress>
     */
    public function all(): Collection
    {
        return $this->locales;
    }

    /**
     * Locales whose every counted attribute is translated.
     */
    public function completeCount(): int
    {
        return $this->locales->filter(fn (LocaleProgress $progress): bool => $progress->missing() === 0)->count();
    }
}
