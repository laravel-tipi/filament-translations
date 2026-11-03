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
use Illuminate\Support\Js;
use Livewire\Attributes\Locked;
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
 * The edited and source locales are in the query string (?locale=ka&source=en),
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

    #[Url(as: 'source', history: true)]
    public ?string $sourceLocale = null;

    /**
     * The raw state of each loaded locale when it was loaded, to tell
     * unsaved changes from locales that were only opened.
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $loadedState = [];

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
        $this->form->fill($this->getRecordEditor()->initialState($this->activeLocale, $this->sourceLocale));
        $this->getRecordEditor()->rememberFormState($this->form);

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

        try {
            $changed = $editor->save($this->form->getState());
        } catch (TranslationConflictException $exception) {
            TranslateAction::sendConflictNotification($editor, $exception);

            return;
        }

        $this->activeLocale = $this->data[RecordEditor::LOCALE] ?? $this->activeLocale;
        $this->sourceLocale = $this->data[RecordEditor::SOURCE_LOCALE] ?? $this->sourceLocale;
        $this->loadedState = [];
        $this->fillForm();

        $this->getSavedNotification($changed)?->send();
    }

    /**
     * @param  list<string>  $changed
     */
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
        $loaded = $this->getRecordEditor()->loaded($data);

        $this->activeLocale = $data[RecordEditor::LOCALE] ?? null;
        $this->sourceLocale = $data[RecordEditor::SOURCE_LOCALE] ?? null;

        foreach (array_keys($loaded) as $locale) {
            $this->loadedState[$locale] ??= $data[RecordEditor::TRANSLATIONS][$locale] ?? null;
        }

        $this->loadedState = array_intersect_key($this->loadedState, $loaded);

        $this->rememberData();
    }

    /**
     * The data as loaded: unsaved changes are differences in the loaded
     * locales, not locales opened or sources switched since.
     */
    protected function rememberData(): void
    {
        if (! $this->hasUnsavedDataChangesAlert()) {
            return;
        }

        $data = $this->data ?? [];

        foreach ($this->loadedState as $locale => $state) {
            $data[RecordEditor::TRANSLATIONS][$locale] = $state;
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
