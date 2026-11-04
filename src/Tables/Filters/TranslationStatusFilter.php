<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Tables\Filters;

use Filament\Forms\Components\Select;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as BaseCollection;
use LogicException;
use Tipi\Support\Locale;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Filament\Editor\RecordEditor;
use Tipi\Translations\Filament\Enums\TranslationStatus;
use Tipi\Translations\Filament\Resources\Concerns\Translatable;
use Tipi\Translations\Filament\Support\LocaleProgress;
use Tipi\Translations\Filament\Support\TranslationProgress;
use Tipi\Translations\Filament\TranslationEditor;

/**
 * Filters records by the translation status of one locale, exactly as the
 * column and the editor report it (LocaleProgress): what readers see is
 * not translated, incomplete or complete; with translation states, also
 * outdated (marked so, or with published texts whose source text changed
 * since) and records with unpublished drafts.
 *
 * Statuses count segments, which only the content schema can tell, so
 * they are decided in PHP: an SQL query using the Translations query
 * scopes (every storage) first narrows the records down to those that can
 * match, then each candidate's progress is computed, in chunks with its
 * translations eager loaded, and the table is limited to the matches.
 * The cost grows with the number of candidates.
 */
class TranslationStatusFilter extends Filter
{
    public const string MISSING = 'missing';

    public const string INCOMPLETE = 'incomplete';

    public const string COMPLETE = 'complete';

    public const string OUTDATED = 'outdated';

    public const string DRAFTS = 'drafts';

    /**
     * Candidates read per query while filtering.
     */
    protected int $chunkSize = 200;

    protected ?RecordEditor $editor = null;

    public static function getDefaultName(): ?string
    {
        return 'translation_status';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('tipi-filament-translations::editor.filter.label'))
            ->schema(fn (): array => [
                Select::make('locale')
                    ->label(__('tipi-filament-translations::editor.filter.locale'))
                    ->options(fn (): array => $this->getLocales()->map(fn (Locale $locale): string => $locale->name)->all()),
                Select::make('status')
                    ->label(__('tipi-filament-translations::editor.filter.status'))
                    ->options(fn (): array => $this->getStatusOptions()),
            ])
            ->query(fn (Builder $query, array $data): Builder => $this->applyStatus($query, $data['locale'] ?? null, $data['status'] ?? null))
            ->indicateUsing(function (array $data): array {
                $locale = $this->getLocales()->get($data['locale'] ?? '');
                $status = $this->getStatusOptions()[$data['status'] ?? ''] ?? null;

                if ($locale === null || $status === null) {
                    return [];
                }

                return [
                    Indicator::make(__('tipi-filament-translations::editor.filter.indicator', ['locale' => $locale->name, 'status' => $status])),
                ];
            });
    }

    /**
     * @return array<string, string>
     */
    public function getStatusOptions(): array
    {
        $options = [
            self::MISSING => __('tipi-filament-translations::editor.filter.missing'),
            self::INCOMPLETE => __('tipi-filament-translations::editor.filter.incomplete'),
            self::COMPLETE => __('tipi-filament-translations::editor.filter.complete'),
        ];

        if ($this->getEditor()->supportsStates()) {
            $options[self::OUTDATED] = __('tipi-filament-translations::editor.filter.outdated');
            $options[self::DRAFTS] = __('tipi-filament-translations::editor.filter.drafts');
        }

        return $options;
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function applyStatus(Builder $query, mixed $locale, mixed $status): Builder
    {
        if (! is_string($locale) || ! is_string($status) || ! $this->getLocales()->has($locale) || ! array_key_exists($status, $this->getStatusOptions())) {
            return $query;
        }

        $candidates = $this->narrow((clone $query)->reorder(), $locale, $status)->withTranslations();

        if ($this->getEditor()->supportsStates()) {
            $candidates->with('translationStates');
        }

        $keys = [];
        $target = $this->getLocales()->only([$locale]);
        $schema = $this->getEditor()->schema();

        $candidates->chunkById($this->chunkSize, function (Collection $records) use (&$keys, $locale, $status, $target, $schema): void {
            foreach ($records as $record) {
                $editor = (new RecordEditor($this->getEditor()->config, $record))->useSchema($schema);
                $progress = TranslationProgress::of($editor->content(), $target)->locale($locale);

                if ($progress !== null && self::matches($progress, $status)) {
                    $keys[] = $record->getKey();
                }
            }
        });

        return $query->whereKey($keys);
    }

    public static function matches(LocaleProgress $progress, string $status): bool
    {
        return match ($status) {
            self::MISSING => $progress->status() === TranslationStatus::Missing,
            self::INCOMPLETE => $progress->status() === TranslationStatus::Incomplete,
            self::COMPLETE => $progress->status() === TranslationStatus::Complete,
            self::OUTDATED => $progress->isOutdated(),
            self::DRAFTS => $progress->hasDrafts(),
            default => false,
        };
    }

    /**
     * Only records that can have the status: conditions every match
     * meets, in SQL. Published values only, like the status.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    protected function narrow(Builder $query, string $locale, string $status): Builder
    {
        $attributes = $this->getEditor()->attributes();

        // A source locale per record can't be narrowed by in SQL.
        $source = $this->getEditor()->config->hasSourceLocalePerRecord() ? null : $this->getEditor()->sourceLocale()->code;

        // Something to translate: the source has a value. Drafts can
        // outlive the source text they were made from.
        if ($source !== null && $status !== self::DRAFTS) {
            $query->where(function (Builder $query) use ($attributes, $source): void {
                foreach ($attributes as $attribute) {
                    $query->orWhere(fn (Builder $query) => $query->whereHasTranslation($attribute, $source));
                }
            });
        }

        return match ($status) {
            // Every attribute the source has is published.
            self::COMPLETE => $source === null ? $query : $query->where(function (Builder $query) use ($attributes, $locale, $source): void {
                foreach ($attributes as $attribute) {
                    $query->where(fn (Builder $query) => $query
                        ->whereDoesntHaveTranslation($attribute, $source)
                        ->orWhere(fn (Builder $query) => $query->whereHasTranslation($attribute, $locale)));
                }
            }),
            // Something is published.
            self::INCOMPLETE => $query->where(function (Builder $query) use ($attributes, $locale): void {
                foreach ($attributes as $attribute) {
                    $query->orWhere(fn (Builder $query) => $query->whereHasTranslation($attribute, $locale));
                }
            }),
            // Marked outdated, or published with recorded sources.
            self::OUTDATED => $query->whereHas('translationStates', fn (Builder $query) => $query
                ->where('locale_code', $locale)
                ->where(fn (Builder $query) => $query->whereNotNull('outdated_at')->orWhereNotNull('sources'))),
            // Drafts of any attribute: the editor's are decided per record.
            self::DRAFTS => $query->whereHas('translationStates', fn (Builder $query) => $query
                ->where('locale_code', $locale)
                ->whereNotNull('drafts')),
            default => $query,
        };
    }

    /**
     * The target locales: offered, except the source.
     *
     * @return BaseCollection<string, Locale>
     */
    protected function getLocales(): BaseCollection
    {
        return $this->getEditor()->offeredLocales()->except($this->getEditor()->sourceLocale()->code);
    }

    /**
     * An editor for the table's model, for its locales and attributes.
     */
    protected function getEditor(): RecordEditor
    {
        if ($this->editor !== null) {
            return $this->editor;
        }

        $model = $this->getTable()->getModel();
        $record = new $model;

        if (! $record instanceof TranslatableModel) {
            throw new LogicException("The translation status filter needs a translatable model, [{$model}] is not.");
        }

        $livewire = $this->getLivewire();
        $resource = method_exists($livewire, 'getResource') ? $livewire::getResource() : null;

        $config = is_string($resource) && in_array(Translatable::class, class_uses_recursive($resource), true)
            ? $resource::getTranslationEditor()
            : TranslationEditor::make();

        return $this->editor = new RecordEditor($config, $record);
    }
}
