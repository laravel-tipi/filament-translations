<?php

declare(strict_types=1);

use Filament\Forms\Components\Select;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Orchestra\Testbench\TestCase;
use Tipi\Translations\Filament\Actions\EditTranslationAction;
use Tipi\Translations\Filament\Actions\TranslateAction;
use Tipi\Translations\Filament\Schemas\Components\TranslationContainer;
use Tipi\Translations\Filament\Schemas\Components\TranslationMarkdownEditor;
use Tipi\Translations\Filament\Schemas\Components\TranslationRichEditor;
use Tipi\Translations\Filament\Schemas\Components\TranslationTextarea;
use Tipi\Translations\Filament\Schemas\Components\TranslationTextInput;

final class TranslationDehydrationLivewire extends Component implements HasSchemas
{
    use InteractsWithSchemas;

    public array $data = [];
}

uses(TestCase::class);

function translationDocument(): array
{
    return ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Hello world', 'marks' => [['type' => 'bold']]],
        ]],
    ]];
}

function translationContainerFor($field, mixed $source, mixed $target): TranslationContainer
{
    $livewire = new TranslationDehydrationLivewire;
    $livewire->data = [
        'nested' => [
            'source_translation' => ['body' => $source],
            'target_translation' => ['body' => $target],
        ],
    ];
    $container = TranslationContainer::make()->statePath('nested')->content(
        'body',
        [$field->getClone()->hydrateTranslation(false)->statePath('source_translation.body')],
        [$field->getClone()->hydrateTranslation(false)->statePath('target_translation.body')],
    );
    Schema::make($livewire)->statePath('data')->components([$container])->getComponents();

    return $container;
}

it('dehydrates rich editor documents as HTML on both sides', function (): void {
    $container = translationContainerFor(TranslationRichEditor::make('body'), translationDocument(), translationDocument());

    expect($container->getTargetState())->toBe(['body' => '<p><strong>Hello world</strong></p>'])
        ->and($container->getSourceState())->toBe(['body' => '<p><strong>Hello world</strong></p>']);
});

it('preserves rich editor JSON mode', function (): void {
    $container = translationContainerFor(TranslationRichEditor::make('body')->json(), translationDocument(), translationDocument());

    expect($container->getTargetState())->toBe(['body' => translationDocument()])
        ->and($container->getSourceState())->toBe(['body' => translationDocument()]);
});

it('validates only the side being saved', function (string $side): void {
    $container = translationContainerFor(
        TranslationTextInput::make('body')->required(),
        $side === 'source' ? 'Source' : null,
        $side === 'target' ? 'Target' : null,
    );

    expect($side === 'source' ? $container->getSourceState() : $container->getTargetState())
        ->toBe(['body' => ucfirst($side)]);

    expect(fn () => $side === 'source' ? $container->getTargetState() : $container->getSourceState())
        ->toThrow(ValidationException::class);
})->with(['source', 'target']);

it('dehydrates ordinary text fields and nullable values', function (string $class, mixed $value, mixed $expected): void {
    $container = translationContainerFor($class::make('body'), $value, $value);

    expect($container->getSourceState())->toBe(['body' => $expected])
        ->and($container->getTargetState())->toBe(['body' => $expected]);
})->with([
    TranslationTextInput::class,
    TranslationTextarea::class,
    TranslationMarkdownEditor::class,
])->with([
    ['Hello world', 'Hello world'],
    ['', null],
    [null, null],
]);

it('runs dehydration hooks and callbacks without changing rendered field containers', function (): void {
    $field = TranslationTextInput::make('body')
        ->beforeStateDehydrated(fn ($component) => $component->state('hooked'), shouldUpdateValidatedStateAfter: true)
        ->dehydrateStateUsing(fn ($state) => strtoupper($state));
    $container = translationContainerFor($field, 'source', 'target');
    $schema = $container->getTargetContentSchema('body');
    $renderedField = $schema->getComponents()[0];

    expect($container->getTargetState())->toBe(['body' => 'HOOKED'])
        ->and($renderedField->getContainer())->toBe($schema)
        ->and($container->getLivewire()->data['nested']['source_translation']['body'])->toBe('source');
});

trait CapturesTranslationSaves
{
    public array $saved = [];

    public function container(): TranslationContainer
    {
        return $this->getTranslationContainer();
    }

    public function hasSourceTranslation(): bool
    {
        return true;
    }

    protected function getSourceLocaleSelect(): Select
    {
        return Select::make('source_locale_code')->options(['en' => 'English']);
    }

    protected function getTargetLocaleSelect(): Select
    {
        return Select::make('target_locale_code')->options(['ka' => 'Georgian']);
    }
}

final class CapturingTranslateAction extends TranslateAction
{
    use CapturesTranslationSaves;

    public function getTargetLocaleCode(): ?string
    {
        return 'ka';
    }

    protected function createTargetTranslation(string $localeCode, array $attributes): void
    {
        $this->saved[$localeCode] = $attributes;
    }

    protected function updateSourceTranslation(string $localeCode, array $attributes): void
    {
        $this->saved[$localeCode] = $attributes;
    }
}

final class CapturingEditTranslationAction extends EditTranslationAction
{
    use CapturesTranslationSaves;

    protected function updateTranslation(string $localeCode, array $attributes): void
    {
        $this->saved[$localeCode] = $attributes;
    }
}

it('passes independently dehydrated state to each action save', function (string $class, string $side, bool $json): void {
    $action = $class::make()->recordTitle('Test')->translationSchema([
        'body' => fn () => TranslationRichEditor::make('body')->json($json)->required(),
    ]);
    if ($action instanceof CapturingEditTranslationAction) {
        $action->localeCode('ka');
    }

    $livewire = new TranslationDehydrationLivewire;
    $livewire->data = [
        'source_locale_code' => 'en',
        'target_locale_code' => 'ka',
        'source_translation' => ['body' => $side === 'source' ? translationDocument() : null],
        'target_translation' => ['body' => $side === 'target' ? translationDocument() : null],
    ];
    $container = $action->container();
    Schema::make($livewire)->statePath('data')->components([$container])->getComponents();
    $footer = $side === 'source' ? $container->getSourceFooterSchema() : $container->getTargetFooterSchema();
    $footer->getComponents()[0]->call();

    expect($action->saved)->toBe([
        $side === 'source' ? 'en' : 'ka' => ['body' => $json ? translationDocument() : '<p><strong>Hello world</strong></p>'],
    ]);
})->with([CapturingTranslateAction::class, CapturingEditTranslationAction::class])
    ->with(['source', 'target'])
    ->with(['HTML' => false, 'JSON' => true]);

it('matches standard schema dehydration for empty rich editor values', function (mixed $value, bool $json): void {
    $field = TranslationRichEditor::make('body')->hydrateTranslation(false)->json($json);
    $container = translationContainerFor($field, $value, $value);
    $livewire = new TranslationDehydrationLivewire;
    $livewire->data = ['body' => $value];
    $standard = Schema::make($livewire)->statePath('data')->components([$field->statePath('body')]);

    expect($container->getTargetState())->toBe($standard->getState())
        ->and($container->getSourceState())->toBe($standard->getState());
})->with([[null], [''], [['type' => 'doc', 'content' => []]]])
    ->with(['HTML' => false, 'JSON' => true]);
