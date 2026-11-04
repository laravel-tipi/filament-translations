<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Editor\Components;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Tipi\Support\Locale;
use Tipi\Translations\Filament\Editor\RecordEditor;
use Tipi\Translations\Filament\Support\LocaleProgress;
use Tipi\Translations\Models\TranslationState;

/**
 * The editor of one target locale: its header and actions, and a group
 * per attribute. Bound to translations.{locale}, dehydrated (validated,
 * saved) only when its texts changed.
 *
 * @internal
 */
final class LocalePanel extends Component
{
    protected string $view = 'tipi-filament-translations::components.locale-panel';

    private RecordEditor $editor;

    private Locale $locale;

    private ?Group $content = null;

    public static function make(RecordEditor $editor, Locale $locale): self
    {
        $panel = app(self::class);
        $panel->editor = $editor;
        $panel->locale = $locale;
        $panel->configure();

        $panel
            ->key('tipi-translation-panel-'.$locale->code)
            ->visible(fn (Get $get): bool => array_key_exists($locale->code, (array) $get(RecordEditor::LOADED)))
            ->childComponents(fn (): array => [$panel->content()], 'rows')
            ->childComponents([$panel->actions()], 'actions');

        return $panel;
    }

    private function content(): Group
    {
        $editor = $this->editor;
        $code = $this->locale->code;

        return $this->content ??= Group::make(fn (): array => array_values(array_map(
            fn (string $attribute, array $segments): AttributeGroup => AttributeGroup::make($editor, $this->locale, $attribute, $segments),
            array_keys($groups = $editor->segmentsByAttribute($code)),
            $groups,
        )))
            ->statePath(RecordEditor::TRANSLATIONS.'.'.$code)
            ->dehydrated(fn (Group $component): bool => $editor->isChanged($component->getRootContainer(), $code))
            ->validatedWhenNotDehydrated(false);
    }

    private function actions(): ActionGroup
    {
        $editor = $this->editor;
        $code = $this->locale->code;
        $panel = $this;

        $actions = [];

        if ($editor->supportsStates()) {
            $actions[] = Action::make('markCurrent')
                ->label(__('tipi-filament-translations::editor.actions.mark_current'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->visible(fn (): bool => $panel->getProgress()->isOutdated())
                ->action(function () use ($editor, $code): void {
                    $editor->markCurrent($code);
                    $this->notify(__('tipi-filament-translations::editor.notifications.marked_current', ['locale' => $this->locale->name]));
                });

            $actions[] = Action::make('markOutdated')
                ->label(__('tipi-filament-translations::editor.actions.mark_outdated'))
                ->icon(Heroicon::OutlinedClock)
                ->visible(fn (): bool => $panel->getProgress()->hasContent() && ! $panel->getProgress()->isOutdated())
                ->action(function () use ($editor, $code): void {
                    $editor->markOutdated($code);
                    $this->notify(__('tipi-filament-translations::editor.notifications.marked_outdated', ['locale' => $this->locale->name]));
                });

            if (($enum = TranslationState::statusEnum()) !== null) {
                $actions[] = Action::make('setWorkflowStatus')
                    ->label(__('tipi-filament-translations::editor.actions.set_status'))
                    ->icon(Heroicon::OutlinedTag)
                    ->modalWidth('md')
                    ->fillForm(fn (): array => ['status' => $panel->getProgress()->workflowStatus])
                    ->schema([
                        Select::make('status')
                            ->label(__('tipi-filament-translations::editor.actions.status'))
                            ->options($enum),
                    ])
                    ->action(function (array $data) use ($editor, $code, $enum): void {
                        $status = $data['status'] ?? null;
                        $editor->setWorkflowStatus($code, $status instanceof BackedEnum || $status === null ? $status : $enum::from($status));
                        $this->notify(__('tipi-filament-translations::editor.notifications.status_updated', ['locale' => $this->locale->name]));
                    });
            }
        }

        $actions[] = Action::make('deleteTranslation')
            ->label(__('tipi-filament-translations::editor.actions.delete'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (): string => __('tipi-filament-translations::editor.actions.delete_heading', ['locale' => $this->locale->name]))
            ->modalDescription(fn (): string => __('tipi-filament-translations::editor.actions.delete_description', [
                'locale' => $this->locale->name,
                'record' => $editor->recordTitle(),
            ]))
            ->modalSubmitActionLabel(__('tipi-filament-translations::editor.actions.delete'))
            ->visible(fn (): bool => $panel->getProgress()->hasContent() && $editor->canDelete($code))
            ->action(function () use ($editor, $code, $panel): void {
                $editor->deleteTranslation($code);
                $editor->reloadLocale($panel->getRootContainer(), $code);
                $this->notify(__('tipi-filament-translations::editor.notifications.deleted', ['locale' => $this->locale->name]));
            });

        return ActionGroup::make($actions)
            ->label(__('tipi-filament-translations::editor.actions.label', ['locale' => $this->locale->name]))
            ->icon(Heroicon::EllipsisVertical)
            ->color('gray')
            ->iconButton();
    }

    private function notify(string $title): void
    {
        Notification::make()->success()->title($title)->send();
    }

    public function getLocale(): Locale
    {
        return $this->locale;
    }

    public function getProgress(): LocaleProgress
    {
        return $this->editor->localeProgress($this->locale->code);
    }

    public function isActive(): bool
    {
        return ($this->getRootContainer()->getRawState()[RecordEditor::LOCALE] ?? null) === $this->locale->code;
    }

    public function isComparison(): bool
    {
        return $this->editor->config->isComparison();
    }

    public function getReferenceLocale(): Locale
    {
        $code = $this->getRootContainer()->getRawState()[RecordEditor::REFERENCE] ?? null;
        $options = $this->editor->referenceOptions($this->locale->code);

        return is_string($code) && $options->has($code) ? $options->get($code) : $this->editor->sourceLocale();
    }

    public function getSourceLocale(): Locale
    {
        return $this->editor->sourceLocale();
    }

    public function hasSegments(): bool
    {
        return $this->editor->segments($this->locale->code) !== [];
    }

    public function getNavigationStatePath(): string
    {
        $root = $this->getRootContainer()->getStatePath();

        return filled($root) ? $root.'.'.RecordEditor::LOCALE : RecordEditor::LOCALE;
    }
}
