<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Resources\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\HasUnsavedDataChangesAlert;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentView;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Js;
use Livewire\Attributes\Url;
use LogicException;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Filament\Actions\TranslateAction;
use Tipi\Translations\Filament\Editor\RecordEditor;
use Tipi\Translations\Filament\Exceptions\TranslationConflictException;
use Tipi\Translations\Filament\Resources\Concerns\Translatable;

/**
 * The translation page of a record. Register it on a resource that uses the
 * Translatable concern:
 *
 *     class TranslateWine extends TranslateRecord
 *     {
 *         protected static string $resource = WineResource::class;
 *     }
 *
 *     'translations' => TranslateWine::route('/{record}/translations'),
 *
 * The edited and reference locales are in the query string (?locale=ka&reference=de),
 * so links, reloads and browser history open the same editor.
 */
abstract class TranslateRecord extends Page
{
    use HasUnsavedDataChangesAlert;
    use InteractsWithRecord;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    #[Url(as: 'locale', history: true)]
    public ?string $activeLocale = null;

    #[Url(as: 'reference', history: true)]
    public ?string $referenceLocale = null;

    public ?string $previousUrl = null;

    protected ?RecordEditor $recordEditor = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        $this->fillForm();

        $this->previousUrl = url()->previous();
    }

    public static function canAccess(array $parameters = []): bool
    {
        $record = $parameters['record'] ?? null;

        if (! $record instanceof TranslatableModel) {
            return false;
        }

        return (new RecordEditor(static::getResource()::getTranslationEditor(), $record))->canAccess();
    }

    public function getRecordEditor(): RecordEditor
    {
        $record = $this->getRecord();

        if (! $record instanceof TranslatableModel) {
            throw new LogicException('The translation page needs a record that implements ['.TranslatableModel::class.'].');
        }

        if ($this->recordEditor?->record !== $record) {
            $resource = static::getResource();

            if (! in_array(Translatable::class, class_uses_recursive($resource), true)) {
                throw new LogicException("The resource [{$resource}] must use the [".Translatable::class.'] concern.');
            }

            $this->recordEditor = new RecordEditor($resource::getTranslationEditor(), $record);
        }

        return $this->recordEditor;
    }

    protected function fillForm(): void
    {
        $this->form->fill($this->getRecordEditor()->initialState($this->activeLocale, $this->referenceLocale));

        $this->syncEditorState();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->model($this->getRecord())
            ->operation('edit')
            ->statePath('data')
            ->components($this->getRecordEditor()->components());
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make($this->getFormActions())
                        ->sticky($this->areFormActionsSticky())
                        ->key('form-actions'),
                ]),
        ]);
    }

    /**
     * After every request, once Filament has applied all updates: keep the
     * URL on the shown locales, remember newly loaded locales, and
     * recompute what counts as unsaved.
     */
    public function dehydrate(): void
    {
        $this->syncEditorState();
    }

    public function save(): void
    {
        $editor = $this->getRecordEditor();

        // Validates the changed locales.
        $this->form->getState();

        try {
            $changed = $editor->save($this->form);
        } catch (TranslationConflictException $exception) {
            TranslateAction::sendConflictNotification($editor, $exception);

            return;
        } catch (ModelNotFoundException) {
            TranslateAction::sendDeletedNotification();

            return;
        }

        $this->syncEditorState();

        $this->getSavedNotification($changed)?->send();
    }

    protected function getSavedNotification(array $changed): ?Notification
    {
        return Notification::make()
            ->success()
            ->title($changed === []
                ? __('tipi-filament-translations::editor.notifications.unchanged')
                : __('tipi-filament-translations::editor.notifications.saved'));
    }

    private function syncEditorState(): void
    {
        $data = $this->data ?? [];

        $this->activeLocale = $data[RecordEditor::LOCALE] ?? null;
        $this->referenceLocale = $data[RecordEditor::REFERENCE] ?? null;

        $this->rememberData();
    }

    /**
     * The data as loaded counts as saved: switching locales or the
     * reference isn't an unsaved change, editing a text is.
     */
    protected function rememberData(): void
    {
        if (! $this->hasUnsavedDataChangesAlert()) {
            return;
        }

        $data = $this->data ?? [];

        foreach ($this->getRecordEditor()->loaded($data) as $locale => $entry) {
            $data[RecordEditor::TRANSLATIONS][$locale] = $entry['base'];
        }

        $this->savedDataHash = md5((string) str(json_encode($data, JSON_UNESCAPED_UNICODE))->replace('\\', ''));
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        $url = $this->previousUrl ?? static::getResource()::getUrl('index');

        return [
            Action::make('save')
                ->label(__('tipi-filament-translations::editor.action.save'))
                ->submit('save')
                // Only changed locales are validated, on the server.
                ->extraAttributes(['formnovalidate' => true])
                ->keyBindings(['mod+s']),
            Action::make('cancel')
                ->label(__('tipi-filament-translations::editor.page.back'))
                ->color('gray')
                ->alpineClickHandler(
                    FilamentView::hasSpaMode($url)
                        ? 'document.referrer ? window.history.back() : Livewire.navigate('.Js::from($url).')'
                        : 'document.referrer ? window.history.back() : (window.location.href = '.Js::from($url).')',
                ),
        ];
    }

    public function getTitle(): string|Htmlable
    {
        return static::$title ?? __('tipi-filament-translations::editor.page.title', ['record' => $this->getRecordEditor()->recordTitle()]);
    }

    public function getBreadcrumb(): string
    {
        return static::$breadcrumb ?? __('tipi-filament-translations::editor.page.breadcrumb');
    }

    public static function getNavigationLabel(): string
    {
        return static::$navigationLabel ?? __('tipi-filament-translations::editor.page.breadcrumb');
    }

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return static::$navigationIcon ?? Heroicon::OutlinedLanguage;
    }
}
