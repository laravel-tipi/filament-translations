<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Actions;

use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;
use Tipi\Support\Locale;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Filament\Editor\RecordEditor;
use Tipi\Translations\Filament\Enums\EditorPresentation;
use Tipi\Translations\Filament\Exceptions\TranslationConflictException;
use Tipi\Translations\Filament\Resources\Concerns\Translatable;
use Tipi\Translations\Filament\TranslationEditor;

/**
 * Opens a record's translation editor: the resource's translation page, or
 * a modal, as the editor's presentation says. Works as a table record
 * action and as a page header action.
 *
 *     TranslateAction::make()
 *     TranslateAction::make()->locale('ka')
 *     TranslateAction::make()->editor(fn (TranslationEditor $editor) => $editor->slideOver())
 *
 * Hidden when the user may not translate any locale of the record.
 */
class TranslateAction extends Action
{
    /**
     * @var class-string<resource>|null
     */
    protected ?string $translationResource = null;

    protected ?Closure $configureEditor = null;

    protected ?Closure $translationForm = null;

    protected string|Closure|null $translationLocale = null;

    /**
     * @var array<int, RecordEditor>
     */
    protected array $recordEditors = [];

    public static function getDefaultName(): ?string
    {
        return 'translate';
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Filament clones actions for each record: closures use the
        // injected action, never $this.
        $this
            ->label(__('tipi-filament-translations::editor.action.label'))
            ->icon(Heroicon::OutlinedLanguage)
            ->color('gray')
            ->authorize(fn (TranslateAction $action): bool => $action->getRecordEditor()?->canAccess() ?? false)
            ->url(fn (TranslateAction $action, array $arguments): ?string => $action->getPageUrl($arguments['locale'] ?? null))
            ->modalHeading(fn (TranslateAction $action): string => __('tipi-filament-translations::editor.action.heading', [
                'record' => $action->getRecordEditor()?->recordTitle(),
            ]))
            ->modalWidth(fn (TranslateAction $action): Width => $action->getEditorConfig()->isComparison() ? Width::SevenExtraLarge : Width::FourExtraLarge)
            ->slideOver(fn (TranslateAction $action): bool => $action->getEditorConfig()->getPresentation() === EditorPresentation::SlideOver)
            ->closeModalByClickingAway(false)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitActionLabel(__('tipi-filament-translations::editor.action.save'))
            // The server validates only changed locales: the browser must not
            // block saving over required fields of locales opened only to look.
            ->modalSubmitAction(fn (Action $action): Action => $action->extraAttributes(['formnovalidate' => true]))
            ->mountUsing(function (TranslateAction $action, ?Schema $schema, array $arguments): void {
                $schema?->fill($action->requireRecordEditor()->initialState($action->requestedLocale($arguments)));
            })
            ->schema(fn (TranslateAction $action): array => $action->requireRecordEditor()->components())
            ->action(function (TranslateAction $action, Schema $schema): void {
                $action->saveTranslations($schema);
            });
    }

    /**
     * Configure the editor for this action, on top of the panel's and the
     * resource's settings.
     *
     * @param  Closure(TranslationEditor): (TranslationEditor|void)  $configure
     */
    public function editor(Closure $configure): static
    {
        $this->configureEditor = $configure;

        return $this;
    }

    /**
     * The resource whose translation form and page to use. Detected from
     * the page when the record is the resource's model.
     *
     * @param  class-string<resource>  $resource
     */
    public function resource(string $resource): static
    {
        $this->translationResource = $resource;

        return $this;
    }

    /**
     * A translation form for records outside a translatable resource.
     *
     * @param  Closure(Schema, Locale): Schema  $form
     */
    public function translationForm(Closure $form): static
    {
        $this->translationForm = $form;

        return $this;
    }

    /**
     * Open the editor at this locale.
     */
    public function locale(string|Closure|null $locale): static
    {
        $this->translationLocale = $locale;

        return $this;
    }

    public function getEditorConfig(): TranslationEditor
    {
        $resource = $this->getTranslationResource();

        $editor = $resource === null ? TranslationEditor::make() : $resource::getTranslationEditor();

        if ($this->translationForm !== null) {
            $editor->form($this->translationForm);
        }

        if ($this->configureEditor !== null) {
            $configured = $this->evaluate($this->configureEditor, ['editor' => $editor]);
            $editor = $configured instanceof TranslationEditor ? $configured : $editor;
        }

        return $editor;
    }

    /**
     * @internal
     */
    public function getRecordEditor(): ?RecordEditor
    {
        $record = $this->getRecord();

        if (! $record instanceof Model || ! $record instanceof TranslatableModel) {
            return null;
        }

        return $this->recordEditors[spl_object_id($record)] ??= new RecordEditor($this->getEditorConfig(), $record);
    }

    protected function requireRecordEditor(): RecordEditor
    {
        return $this->getRecordEditor() ?? throw new LogicException(
            'The translate action needs a record that implements ['.TranslatableModel::class.'].',
        );
    }

    /**
     * The resource of the page or table, when it is translatable and the
     * record is its model.
     *
     * @return class-string<resource>|null
     */
    protected function getTranslationResource(): ?string
    {
        if ($this->translationResource !== null) {
            return $this->translationResource;
        }

        $livewire = $this->getLivewire();
        $resource = method_exists($livewire, 'getResource') ? $livewire::getResource() : null;
        $record = $this->getRecord();

        if (
            ! is_string($resource)
            || ! in_array(Translatable::class, class_uses_recursive($resource), true)
            || ($record instanceof Model && ! is_a($record, $resource::getModel()))
        ) {
            return null;
        }

        return $resource;
    }

    /**
     * The translation page URL, or null to open the modal.
     */
    protected function getPageUrl(?string $locale): ?string
    {
        $editor = $this->getRecordEditor();

        if ($editor === null || $editor->config->getPresentation()->opensModal()) {
            return null;
        }

        $resource = $editor->config->getResource() ?? throw new LogicException(
            'The page presentation needs a resource that uses the ['.Translatable::class.'] concern.',
        );

        if (! $resource::hasPage('translations')) {
            throw new LogicException("The resource [{$resource}] has no [translations] page. Register a TranslateRecord page in getPages(): 'translations' => TranslateWine::route('/{record}/translations').");
        }

        return $resource::getUrl('translations', array_filter([
            'record' => $editor->record,
            'locale' => $locale ?? $this->evaluate($this->translationLocale),
        ]));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function requestedLocale(array $arguments): ?string
    {
        $locale = $arguments['locale'] ?? $this->evaluate($this->translationLocale);

        return is_string($locale) ? $locale : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function saveTranslations(Schema $schema): void
    {
        $editor = $this->requireRecordEditor();

        try {
            $changed = $editor->save($schema);
        } catch (TranslationConflictException $exception) {
            static::sendConflictNotification($editor, $exception);

            $this->halt();
        } catch (ModelNotFoundException) {
            static::sendDeletedNotification();

            $this->halt();
        }

        Notification::make()
            ->success()
            ->title($changed === []
                ? __('tipi-filament-translations::editor.notifications.unchanged')
                : __('tipi-filament-translations::editor.notifications.saved'))
            ->send();
    }

    /**
     * Tell what conflicted: locale and field, and why.
     */
    public static function sendConflictNotification(RecordEditor $editor, TranslationConflictException $exception): void
    {
        $lines = [];

        foreach ($exception->conflicts as $conflict) {
            $segment = $conflict['key'] === null ? null : ($editor->segments($conflict['locale'])[$conflict['key']] ?? null);
            $field = $editor->schema()->label($conflict['attribute']);

            if ($segment !== null && $segment->context !== []) {
                $field .= ' › '.implode(' › ', $segment->context);
            }

            $lines[] = __('tipi-filament-translations::editor.notifications.conflict_'.$conflict['reason'], [
                'locale' => $editor->locales()->get($conflict['locale'])->name ?? $conflict['locale'],
                'field' => $field,
            ]);
        }

        $lines = array_values(array_unique($lines));
        $more = count($lines) > 5 ? ' …' : '';

        Notification::make()
            ->danger()
            ->persistent()
            ->title(__('tipi-filament-translations::editor.notifications.conflict_title'))
            ->body(__('tipi-filament-translations::editor.notifications.conflict_body', [
                'conflicts' => implode('; ', array_slice($lines, 0, 5)).$more,
            ]))
            ->send();
    }

    public static function sendDeletedNotification(): void
    {
        Notification::make()
            ->danger()
            ->persistent()
            ->title(__('tipi-filament-translations::editor.notifications.deleted_record'))
            ->send();
    }
}
