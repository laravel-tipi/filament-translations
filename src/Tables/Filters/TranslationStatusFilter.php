<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Tables\Filters;

use Filament\Forms\Components\Select;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Tipi\Support\Locale;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Filament\Editor\RecordEditor;
use Tipi\Translations\Filament\Resources\Concerns\Translatable;
use Tipi\Translations\Filament\TranslationEditor;

/**
 * Filters records by the translation status of one locale: missing,
 * incomplete, complete or, with translation states, outdated. Counts the
 * attributes of the translation form, like the editor and the column.
 * Uses the Translations query scopes, so it works with every storage.
 */
class TranslationStatusFilter extends Filter
{
    public const string MISSING = 'missing';

    public const string INCOMPLETE = 'incomplete';

    public const string COMPLETE = 'complete';

    public const string OUTDATED = 'outdated';

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
                    ->options(fn (): array => $this->getEditor()->offeredLocales()->map(fn (Locale $locale): string => $locale->name)->all()),
                Select::make('status')
                    ->label(__('tipi-filament-translations::editor.filter.status'))
                    ->options(fn (): array => $this->getStatusOptions()),
            ])
            ->query(fn (Builder $query, array $data): Builder => $this->applyStatus($query, $data['locale'] ?? null, $data['status'] ?? null))
            ->indicateUsing(function (array $data): array {
                $locale = $this->getEditor()->offeredLocales()->get($data['locale'] ?? '');
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
        }

        return $options;
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function applyStatus(Builder $query, mixed $locale, mixed $status): Builder
    {
        if (! is_string($locale) || ! is_string($status) || ! $this->getEditor()->offeredLocales()->has($locale)) {
            return $query;
        }

        $attributes = $this->getEditor()->attributes();

        return match ($status) {
            self::COMPLETE => $query->where(function (Builder $query) use ($attributes, $locale): void {
                foreach ($attributes as $attribute) {
                    $query->whereHasTranslation($attribute, $locale);
                }
            }),
            self::MISSING => $query->where(function (Builder $query) use ($attributes, $locale): void {
                foreach ($attributes as $attribute) {
                    $query->whereDoesntHaveTranslation($attribute, $locale);
                }
            }),
            self::INCOMPLETE => $query->where(function (Builder $query) use ($attributes, $locale): void {
                foreach ($attributes as $attribute) {
                    $query->orWhere(fn (Builder $query) => $query->whereDoesntHaveTranslation($attribute, $locale));
                }
            }),
            self::OUTDATED => $this->getEditor()->supportsStates()
                ? $query->whereHas('translationStates', fn (Builder $query) => $query
                    ->where('locale_code', $locale)
                    ->whereNotNull('outdated_at'))
                : $query,
            default => $query,
        };
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
