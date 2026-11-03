<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Tables\Columns;

use Closure;
use Filament\Tables\Columns\Column;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Filament\Editor\RecordEditor;
use Tipi\Translations\Filament\Resources\Concerns\Translatable;
use Tipi\Translations\Filament\Support\LocaleProgress;
use Tipi\Translations\Filament\Support\TranslationProgress;
use Tipi\Translations\Filament\TranslationEditor;

/**
 * Each locale's translation progress for a record, as compact badges that
 * open the editor at that locale through the table's TranslateAction.
 * With more locales than the limit, one summary badge is shown instead.
 *
 * Eager loads translations (and translation states) for the table, so the
 * column costs no queries per row.
 */
class TranslationsColumn extends Column
{
    protected string $view = 'tipi-filament-translations::tables.translations-column';

    protected int|Closure $limit = TranslationEditor::TABS_LIMIT + 1;

    protected string $actionName = 'translate';

    protected ?TranslationEditor $editorConfig = null;

    /**
     * @var list<string>|null
     */
    protected ?array $countedAttributes = null;

    public static function make(?string $name = 'translations'): static
    {
        return parent::make($name ?? 'translations');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The badges are links and buttons of their own: the cell must not
        // be wrapped in the row's record link.
        $this
            ->label(__('tipi-filament-translations::editor.column.label'))
            ->disabledClick();
    }

    /**
     * Show one badge per locale up to this many locales, a summary above.
     */
    public function limit(int|Closure $limit): static
    {
        $this->limit = $limit;

        return $this;
    }

    /**
     * The name of the table action the badges open. Default: "translate".
     */
    public function translateAction(string $name): static
    {
        $this->actionName = $name;

        return $this;
    }

    public function getLimit(): int
    {
        return (int) $this->evaluate($this->limit);
    }

    public function applyEagerLoading(Builder|Relation $query): Builder|Relation
    {
        $query = parent::applyEagerLoading($query);
        $model = $query->getModel();

        if ($model instanceof TranslatableModel && $query instanceof Builder) {
            $query->withTranslations();
        }

        if (TranslationProgress::supportsStates($model) && ! array_key_exists('translationStates', $query->getEagerLoads())) {
            $query->with('translationStates');
        }

        return $query;
    }

    /**
     * @return list<array{progress: LocaleProgress, href: string|null, click: string|null}>
     */
    public function getItems(): array
    {
        $record = $this->getRecord();

        if (! $record instanceof TranslatableModel) {
            return [];
        }

        $editor = $this->getRecordEditor($record);

        return $editor->progress()->all()
            ->map(fn (LocaleProgress $progress): array => [
                'progress' => $progress,
                ...$this->getLink($record, $progress->locale->code),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{href: string|null, click: string|null}
     */
    public function getLink(Model $record, ?string $locale): array
    {
        $action = $this->getTable()->getAction($this->actionName);

        if ($action === null) {
            return ['href' => null, 'click' => null];
        }

        $action = $action($locale === null ? [] : ['locale' => $locale])->record($record);

        if (! $action->isVisible()) {
            return ['href' => null, 'click' => null];
        }

        $url = $action->getUrl();

        return ['href' => $url, 'click' => $url === null ? $action->getLivewireClickHandler() : null];
    }

    /**
     * @param  Model&TranslatableModel  $record
     */
    protected function getRecordEditor(Model $record): RecordEditor
    {
        $editor = new RecordEditor($this->getEditorConfig(), $record);

        $this->countedAttributes ??= $editor->attributes();

        return $editor->useAttributes($this->countedAttributes);
    }

    protected function getEditorConfig(): TranslationEditor
    {
        if ($this->editorConfig !== null) {
            return $this->editorConfig;
        }

        $livewire = $this->getLivewire();
        $resource = method_exists($livewire, 'getResource') ? $livewire::getResource() : null;

        if (is_string($resource) && in_array(Translatable::class, class_uses_recursive($resource), true)) {
            return $this->editorConfig = $resource::getTranslationEditor();
        }

        $action = $this->getTable()->getAction($this->actionName);

        if (method_exists($action, 'getEditorConfig')) {
            return $this->editorConfig = $action->getEditorConfig();
        }

        return $this->editorConfig = TranslationEditor::make();
    }
}
