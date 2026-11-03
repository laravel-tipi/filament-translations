<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Tipi\Support\Locale;
use Tipi\Translations\Concerns\HasTranslationStates;
use Tipi\Translations\Filament\Content\RecordContent;

/**
 * The translation progress of a record in each target locale. Derived
 * from the stored content when read, never stored: it stays right when
 * the source changes.
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
     * @param  Collection<string, Locale>  $locales
     */
    public static function of(RecordContent $content, Collection $locales): self
    {
        $record = $content->record;
        $stored = $record->getAllTranslations();
        $attributes = array_flip($content->schema->attributes());

        $states = self::supportsStates($record)
            ? $record->getRelationValue('translationStates')->keyBy('locale_code')
            : collect();

        return new self($locales->map(function (Locale $locale) use ($content, $stored, $attributes, $states): LocaleProgress {
            $state = $states->get($locale->code);
            $progress = $content->progress($locale->code);

            return new LocaleProgress(
                locale: $locale,
                translated: $progress['translated'],
                total: $progress['total'],
                hasValues: array_intersect_key($stored[$locale->code] ?? [], $attributes) !== [],
                review: $progress['review'],
                outdatedSince: $state?->outdated_at,
                workflowStatus: $state?->status,
            );
        }));
    }

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

    public function completeCount(): int
    {
        return $this->locales->filter(fn (LocaleProgress $progress): bool => ! $progress->needsWork())->count();
    }
}
